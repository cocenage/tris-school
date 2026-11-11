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
    public array $previewItems = [];

    public function __construct(private MitStrikeSource $source) {}

    public function sync(bool $dryRun = false): array
    {
        $this->previewItems = [];
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
        $relevantItems = collect($result['items'])
            ->filter(fn (array $item): bool => $this->source->relevant($item))
            ->values()
            ->all();
        $this->previewItems = $relevantItems;
        foreach ($relevantItems as $item) {
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

    /**
     * Persist an official operator notice using the existing alert and delivery
     * ledger. Stable URL identity makes repeated source checks idempotent.
     */
    public function syncExternalNotice(array $event): int
    {
        $source = (string) ($event['source'] ?? '');
        $url = (string) ($event['url'] ?? '');
        if (! in_array($source, ['atm', 'trenord'], true) || $url === '') {
            return 0;
        }

        $metadata = (array) ($event['metadata'] ?? []);
        $status = (string) ($metadata['status'] ?? 'unknown');
        $identity = sha1($source.'|'.mb_strtolower($url));
        $version = hash('sha256', json_encode([
            $event['title'] ?? '', $event['description'] ?? '', $status,
            $event['starts_at'] ?? null, $event['ends_at'] ?? null, $metadata['duration'] ?? null,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        $created = false;

        DB::transaction(function () use ($event, $source, $url, $metadata, $status, $identity, $version, &$created): void {
            $alert = MobilityAlert::query()->where('external_hash', $identity)->lockForUpdate()->first();
            $old = $alert?->strike_metadata ?? [];
            if (($old['version'] ?? null) === $version) {
                return;
            }

            $kind = $alert === null
                ? 'new'
                : match ($status) {
                    'postponed' => 'postponed',
                    'cancelled' => 'cancelled',
                    default => 'updated',
                };
            $revision = (int) ($old['revision'] ?? 0) + 1;
            $metadata = array_merge($metadata, [
                'identity' => $identity,
                'version' => $version,
                'revision' => $revision,
                'notification_kind' => $kind,
                'previous' => array_intersect_key($old, array_flip(['status', 'starts_at', 'ends_at', 'duration', 'notes'])),
            ]);

            $alert ??= new MobilityAlert;
            $alert->fill([
                'external_hash' => $identity,
                'source' => $source,
                'title' => Str::limit((string) ($event['title'] ?? 'Transport strike notice'), 250, ''),
                'description' => $event['description'] ?? null,
                'url' => $url,
                'type' => 'strike',
                'risk' => $event['risk'] ?? 'medium',
                'district' => $event['district'] ?? null,
                'starts_at' => $event['starts_at'] ?? null,
                'ends_at' => $event['ends_at'] ?? null,
                'strike_metadata' => $metadata,
            ])->save();
            $created = $created || $alert->wasRecentlyCreated;

            if (! $this->shouldNotifyExternal($metadata, $kind, $alert->starts_at?->toDateString())) {
                return;
            }

            foreach ($this->targets() as $target) {
                $key = $this->deliveryKey($alert->id, $revision, $version, $target);
                $message = MobilityAlertMessage::firstOrCreate(['delivery_key' => $key], [
                    'mobility_alert_id' => $alert->id,
                    'message_type' => 'strike_alert',
                    'chat_id' => $target['chat'],
                    'thread_id' => $target['thread'],
                    'telegram_message_id' => '',
                    'text' => $this->message($alert, $old),
                ]);
                $message = MobilityAlertMessage::query()->lockForUpdate()->findOrFail($message->id);
                $this->enqueue($message);
            }
        });

        return $created ? 1 : 0;
    }

    private function shouldNotifyExternal(array $metadata, string $kind, ?string $eventDate): bool
    {
        $activation = $this->notificationActivation();
        if (! $activation || ! in_array($metadata['status'] ?? null, ['scheduled', 'updated', 'postponed', 'cancelled'], true)) {
            return false;
        }

        try {
            $published = CarbonImmutable::createFromFormat('!Y-m-d', (string) ($metadata['publication_date'] ?? ''), 'Europe/Rome');
        } catch (\Throwable) {
            return false;
        }
        if (! $published || $published->toDateString() !== ($metadata['publication_date'] ?? null)
            || $published->lt($activation) || $published->gt(CarbonImmutable::now('Europe/Rome'))) {
            return false;
        }

        if ($kind === 'new') {
            return ($metadata['status'] ?? null) === 'scheduled'
                && $eventDate !== null
                && $eventDate >= CarbonImmutable::now('Europe/Rome')->toDateString();
        }

        return true;
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
            'postponed' => '🔄 Забастовка перенесена',
            'updated' => '🔄 Обновление забастовки',
            default => '🚨 Забастовка транспорта',
        };
        $text = '<b>'.$heading.'</b>';
        $text .= $alert->starts_at ? "\nДата: ".$alert->starts_at->format('d.m.Y') : "\nДата проведения не подтверждена.";
        if ($alert->ends_at && $alert->starts_at && ! $alert->ends_at->equalTo($alert->starts_at)) {
            $text .= ' — '.$alert->ends_at->format('d.m.Y');
        }
        foreach (['region' => 'Регион', 'province' => 'Провинция', 'scope' => 'Масштаб', 'operator' => 'Оператор/категория', 'sector' => 'Сектор', 'duration' => 'Продолжительность/часы', 'notes' => 'Уточнения'] as $field => $label) {
            if (filled($item[$field] ?? null)) {
                $text .= "\n".$label.': '.e(Str::limit($item[$field], 300));
                if (isset($old[$field]) && $old[$field] !== $item[$field]) {
                    $text .= ' (ранее: '.e(Str::limit($old[$field], 150)).')';
                }
            }
        }
        if (($item['notification_kind'] ?? '') === 'updated' && isset($old['starts_at']) && $old['starts_at'] !== $item['starts_at']) {
            $text .= "\nПрежняя дата: ".e($old['starts_at']);
        }
        if (($item['status'] ?? null) === 'postponed' && empty($item['starts_at'])) {
            $text .= "\nНовая дата не подтверждена.";
        }
        if (! in_array($item['status'] ?? null, ['cancelled', 'postponed'], true)) {
            $text .= "\nВозможны перебои в движении. Планируйте поездки с запасом времени и проверяйте расписание оператора.";
        }

        return $text."\n<a href=\"".e($alert->url).'\">Официальный источник</a>';
    }
}
