<?php

namespace App\Services\Mobility;

use App\Jobs\DeliverMobilityAlert;
use App\Models\MobilityAlert;
use App\Models\MobilityAlertMessage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class MobilityStrikeSyncService
{
    public function __construct(private MitStrikeSource $source) {}

    public function sync(bool $dryRun = false): array
    {
        $counts = array_fill_keys(['fetched', 'normalized', 'relevant', 'new', 'updated', 'cancelled', 'unchanged', 'queued', 'already_notified', 'would_queue', 'failed'], 0);
        $counts['targets_configured'] = count($this->targets());
        $counts['analytics_bot_configured'] = filled(config('services.telegram.analytics_bot_token'));
        try {
            $result = $this->source->fetch();
        } catch (\Throwable $error) {
            $counts['failed']++;
            if (! $dryRun) {
                Log::warning('Mobility MIT fetch/parse failed; ledger unchanged', $counts + ['error_class' => get_class($error)]);
            }

            return $counts;
        }
        $counts['normalized'] = count($result['items']);
        $counts['failed'] = $result['invalid'];
        $counts['fetched'] = $result['fetched'];
        foreach ($result['items'] as $item) {
            if (! $this->source->relevant($item)) {
                continue;
            }
            if ($item['status'] !== 'cancelled' && $item['ends_at'] < now('Europe/Rome')->toDateString()) {
                continue;
            }
            $counts['relevant']++;
            $before = $counts;
            try {
                $apply = function () use ($item, $dryRun, &$counts): void {
                    $query = MobilityAlert::query()->where('external_hash', $item['identity']);
                    $alert = ($dryRun ? $query : $query->lockForUpdate())->first();
                    $old = $alert?->strike_metadata ?? [];
                    $kind = ! $alert ? 'new' : (($old['version'] ?? null) === $item['version'] ? 'unchanged' : 'updated');
                    if ($item['status'] === 'cancelled' && $kind !== 'unchanged') {
                        $kind = 'cancelled';
                    }
                    $counts[$kind]++;
                    $revision = $kind === 'unchanged' ? ($old['revision'] ?? 1) : ($old['revision'] ?? 0) + 1;
                    if ($dryRun) {
                        if (! $this->shouldNotify($item, $kind, $old)) {
                            if ($alert) {
                                $counts['already_notified'] += MobilityAlertMessage::query()
                                    ->where('mobility_alert_id', $alert->id)
                                    ->where('message_type', 'strike_alert')
                                    ->whereNotNull('sent_at')->count();
                            }

                            return;
                        }
                        foreach ($this->targets() as $target) {
                            $key = $this->deliveryKey($alert?->id ?? 0, $revision, $item['version'], $target);
                            $existing = MobilityAlertMessage::query()->where('delivery_key', $key)->first();
                            if ($existing?->sent_at) {
                                $counts['already_notified']++;
                            } elseif (! $existing || ! $existing->queued_at || $existing->queued_at->lte(now()->subHour())) {
                                $counts['would_queue']++;
                            }
                        }

                        return;
                    }
                    if ($kind !== 'unchanged') {
                        $item['notification_kind'] = $kind;
                        $item['revision'] = $revision;
                        $item['previous'] = array_intersect_key($old, array_flip(['starts_at', 'ends_at', 'duration', 'scope', 'operator', 'sector', 'region', 'province', 'notes']));
                        $alert ??= new MobilityAlert(['external_hash' => $item['identity']]);
                        $alert->fill([
                            'source' => 'mit', 'title' => Str::limit('Sciopero '.$item['operator'], 250, ''),
                            'description' => trim($item['duration'].' '.$item['notes']),
                            'type' => 'strike', 'risk' => $item['status'] === 'cancelled' ? 'low' : 'high',
                            'district' => $item['region'] ?: $item['province'],
                            'starts_at' => $item['starts_at'], 'ends_at' => $item['ends_at'],
                            'url' => config('mobility.strike_source_url'), 'strike_metadata' => $item,
                        ])->save();
                    }
                    if (! $this->shouldNotify($item, $kind, $old)) {
                        return;
                    }
                    foreach ($this->targets() as $target) {
                        $key = $this->deliveryKey($alert->id, $revision, $item['version'], $target);
                        $message = MobilityAlertMessage::firstOrCreate(['delivery_key' => $key], [
                            'mobility_alert_id' => $alert->id, 'message_type' => 'strike_alert',
                            'chat_id' => $target['chat'], 'thread_id' => $target['thread'],
                            'telegram_message_id' => '', 'text' => $this->message($alert, $old),
                        ]);
                        $message = MobilityAlertMessage::query()->lockForUpdate()->findOrFail($message->id);
                        $counts['queued'] += $this->enqueue($message);
                    }
                };
                if ($dryRun) {
                    $apply();
                } else {
                    // Persist the durable reservation first. The queued job is added
                    // after commit and stale reservations are recovered below.
                    DB::transaction($apply);
                }
            } catch (\Throwable $error) {
                if (! $dryRun) {
                    Log::warning('Mobility strike persistence/queue failed', ['error_class' => get_class($error)]);
                }
                $counts = $before;
                $counts['failed']++;
            }
        }
        if (! $dryRun && ($activation = $this->notificationActivation())) {
            MobilityAlertMessage::query()->where('message_type', 'strike_alert')->whereNull('sent_at')->whereNull('deleted_at')
                ->where('created_at', '>=', $activation)
                ->where(fn ($query) => $query->whereNull('queued_at')->orWhere('queued_at', '<=', now()->subHour()))
                ->each(function (MobilityAlertMessage $message) use (&$counts): void {
                    try {
                        $counts['queued'] += DB::transaction(function () use ($message): int {
                            return $this->enqueue(MobilityAlertMessage::query()->lockForUpdate()->findOrFail($message->id));
                        });
                    } catch (\Throwable) {
                        $counts['failed']++;
                    }
                });
            Log::info('Mobility strike sync completed', $counts);
        }

        return $counts;
    }

    private function enqueue(MobilityAlertMessage $message): int
    {
        if ($message->sent_at || ($message->queued_at && $message->queued_at->gt(now()->subHour()))) {
            return 0;
        }
        $message->forceFill(['queued_at' => now()])->save();
        DeliverMobilityAlert::dispatch($message->id)->onConnection('database')->onQueue('default')->afterCommit();

        return 1;
    }

    private function shouldNotify(array $item, string $kind, array $old): bool
    {
        $activation = $this->notificationActivation();
        if (! $activation) {
            return false;
        }

        if ($item['ends_at'] < CarbonImmutable::now('Europe/Rome')->toDateString()) {
            return false;
        }

        if ($kind === 'unchanged') {
            if (in_array($old['notification_kind'] ?? null, ['updated', 'cancelled'], true)) {
                return true;
            }
        } elseif ($kind !== 'new') {
            return true;
        }

        if ($item['status'] === 'cancelled') {
            return false;
        }

        try {
            $announced = CarbonImmutable::createFromFormat('!d/m/Y', (string) ($item['proclaimed_at'] ?? ''), 'Europe/Rome');
        } catch (\Throwable) {
            return false;
        }

        return $announced && $announced->gte($activation) && $announced->lte(CarbonImmutable::now('Europe/Rome'));
    }

    private function notificationActivation(): ?CarbonImmutable
    {
        if (! config('mobility.strike_notifications_enabled')) {
            return null;
        }

        $date = config('mobility.strike_notifications_active_since');
        try {
            $activation = CarbonImmutable::createFromFormat('!Y-m-d', (string) $date, 'Europe/Rome');
        } catch (\Throwable) {
            return null;
        }

        return $activation && $activation->toDateString() === $date ? $activation : null;
    }

    private function deliveryKey(int $alertId, int $revision, string $version, array $target): string
    {
        return hash('sha256', implode('|', [$alertId, $revision, $version, $target['chat'], $target['thread'] ?? '']));
    }

    private function targets(): array
    {
        $chat = trim((string) config('services.telegram.mobility_strike_chat_id'));
        $thread = trim((string) config('services.telegram.mobility_strike_thread_id'));

        return $chat !== '' && $thread !== '' ? [['chat' => $chat, 'thread' => $thread]] : [];
    }

    private function message(MobilityAlert $alert, array $old): string
    {
        $item = $alert->strike_metadata;
        $old = $item['previous'] ?? $old;
        $heading = match ($item['notification_kind'] ?? 'new') {
            'cancelled' => '✅ Забастовка отменена',
            'updated' => '🔄 Обновление забастовки',
            default => '🚨 Забастовка транспорта',
        };
        $text = '<b>'.$heading."</b>\nДата: ".$alert->starts_at->format('d.m.Y');
        if ($alert->ends_at && ! $alert->ends_at->equalTo($alert->starts_at)) {
            $text .= ' — '.$alert->ends_at->format('d.m.Y');
        }
        foreach (['region' => 'Регион', 'province' => 'Провинция', 'scope' => 'Масштаб', 'operator' => 'Оператор/категория', 'sector' => 'Сектор', 'duration' => 'Продолжительность/часы', 'notes' => 'Уточнения'] as $field => $label) {
            if ($item[$field] !== '') {
                $text .= "\n".$label.': '.e(Str::limit($item[$field], 300));
                if (isset($old[$field]) && $old[$field] !== $item[$field]) {
                    $text .= ' (ранее: '.e(Str::limit($old[$field], 150)).')';
                }
            }
        }
        if (($item['notification_kind'] ?? '') === 'updated' && isset($old['starts_at']) && $old['starts_at'] !== $item['starts_at']) {
            $text .= "\nПрежняя дата: ".e($old['starts_at']);
        }
        if ($item['status'] !== 'cancelled') {
            $text .= "\nВозможны перебои в движении. Планируйте поездки с запасом времени и проверяйте расписание оператора.";
        }

        return $text."\n<a href=\"".e($alert->url).'\">Официальный источник MIT</a>';
    }
}
