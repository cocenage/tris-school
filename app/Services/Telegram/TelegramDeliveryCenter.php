<?php

namespace App\Services\Telegram;

use App\Models\MobilityAlertMessage;
use App\Models\TelegramOutboundOperation;
use App\Models\TelegramScheduledControlSummaryDelivery;
use App\Models\TelegramScheduledMessage;
use App\Models\TelegramScheduledMessageDelivery;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;
use Throwable;

/** Bounded dashboard projection. No Telegram API requests or writes. */
class TelegramDeliveryCenter
{
    private const DISTRICTS = ['navigli', 'lodi', 'lambrate', 'como', 'certosa'];

    private array $sourceWarnings = [];

    public function __construct(private TelegramDistrictRouteRegistry $routes) {}

    public function snapshot(?CarbonImmutable $at = null): array
    {
        $this->sourceWarnings = [];
        $now = ($at ?? CarbonImmutable::now('Europe/Rome'))->setTimezone('Europe/Rome');
        $start = $now->startOfDay();
        $date = $start->toDateString();
        $journalAvailable = Schema::hasTable('telegram_outbound_operations');
        $monitorStartedAt = $journalAvailable
            ? TelegramOutboundOperation::query()->oldest('id')->value('created_at')
            : null;
        $operations = $journalAvailable
            ? TelegramOutboundOperation::query()->where('created_at', '>=', $start->format('Y-m-d H:i:s'))
                ->orderByDesc('id')->limit(40)->get()
            : collect();
        $dailyOperations = $journalAvailable
            ? TelegramOutboundOperation::query()->where('created_at', '>=', $start->format('Y-m-d H:i:s'))
                ->where('notification_type', '!=', 'mobility_alert')
                ->where(fn ($query) => $query->whereNull('operation_key')
                    ->orWhereNotIn('notification_type', ['morning_digest', 'evening_intelligence', 'scheduled_control', 'scheduled_control_summary']))
                ->selectRaw('status, COUNT(*) as total')->groupBy('status')->get()
            : collect();

        $sourceError = null;
        try {
            $slots = $this->scheduledSlots($date, $now, $journalAvailable, $monitorStartedAt);
            $slots = [...$slots, ...$this->existingDeliverySlots($date, $now, $journalAvailable)];
        } catch (Throwable $exception) {
            $slots = [];
            $sourceError = 'Источник доставок недоступен: '.class_basename($exception);
        }
        $expected = count($slots) + $dailyOperations->sum('total');
        $counts = ['expected' => $expected, 'sent' => 0, 'pending' => 0, 'failed' => 0, 'missed' => 0, 'uncertain' => 0, 'edited' => 0, 'unmonitored' => 0];
        foreach ($slots as $item) {
            $bucket = match ($item['status']) {
                'sent' => 'sent',
                'edited' => 'edited',
                'failed' => 'failed',
                'missed' => 'missed',
                'uncertain', 'retrying' => 'uncertain',
                'unmonitored' => 'unmonitored',
                default => 'pending',
            };
            $counts[$bucket]++;
        }
        foreach ($dailyOperations as $row) {
            $bucket = $row->status === 'expected' ? 'pending'
                : (array_key_exists($row->status, $counts) ? $row->status : 'pending');
            $counts[$bucket] += (int) $row->total;
        }
        if ($journalAvailable) {
            $stalePending = TelegramOutboundOperation::query()
                ->where('created_at', '>=', $start->format('Y-m-d H:i:s'))
                ->where('notification_type', '!=', 'mobility_alert')
                ->where(fn ($query) => $query->whereNull('operation_key')
                    ->orWhereNotIn('notification_type', ['morning_digest', 'evening_intelligence', 'scheduled_control', 'scheduled_control_summary']))
                ->where('status', 'pending')
                ->where('attempted_at', '<', $now->subMinutes(10)->format('Y-m-d H:i:s'))
                ->count();
            $counts['pending'] -= $stalePending;
            $counts['uncertain'] += $stalePending;

            $staleExpected = TelegramOutboundOperation::query()
                ->where('created_at', '>=', $start->format('Y-m-d H:i:s'))
                ->where('status', 'expected')
                ->where('created_at', '<', $now->subMinutes(10)->format('Y-m-d H:i:s'))
                ->count();
            $counts['pending'] -= $staleExpected;
            $counts['missed'] += $staleExpected;
        }

        return [
            'date' => $date,
            'counts' => $counts,
            'slots' => $slots,
            'operations' => $operations,
            'journal_available' => $journalAvailable,
            'source_error' => implode(' ', array_filter([$sourceError, ...$this->sourceWarnings])) ?: null,
            'coverage_note' => $journalAvailable
                ? 'Журнал связывает поддерживаемые события с HTTP-попытками после установки миграции. Остальные событийные пути и история до запуска покрыты частично.'
                : 'Журнал ещё не установлен: событийные отправки не подтверждены.',
        ];
    }

    private function scheduledSlots(string $date, CarbonImmutable $now, bool $journalAvailable, ?string $monitorStartedAt): array
    {
        $slots = [];
        $graceMinutes = 10;
        $routes = $this->routes->diagnostics()->keyBy('key');
        $keys = [];

        foreach (self::DISTRICTS as $district) {
            $route = $routes->get($district);
            $keys[] = 'morning_digest:'.$date.':'.$district;
            if (config('services.telegram.evening_intelligence_delivery_enabled', false)) {
                $keys[] = 'evening_intelligence:'.$date.':'.$district;
            }
            foreach (['morning_digest' => '08:00', 'evening_intelligence' => '20:30'] as $type => $time) {
                if ($type === 'evening_intelligence' && ! config('services.telegram.evening_intelligence_delivery_enabled', false)) {
                    continue;
                }
                $due = CarbonImmutable::parse($date.' '.$time, 'Europe/Rome');
                $slots[] = [
                    'key' => $type.':'.$date.':'.$district,
                    'type' => $type,
                    'district' => $district,
                    'bot' => $type === 'morning_digest' ? 'analytics' : (config('services.telegram.evening_intelligence_delivery_mode', 'centralized') === 'centralized' ? 'work' : 'analytics'),
                    'chat_id' => $type === 'evening_intelligence' && config('services.telegram.evening_intelligence_delivery_mode', 'centralized') === 'centralized'
                        ? config('services.telegram.evening_intelligence_central_chat_id') : ($route['chat_id'] ?? null),
                    'thread_id' => $type === 'evening_intelligence' && config('services.telegram.evening_intelligence_delivery_mode', 'centralized') === 'centralized'
                        ? config('services.telegram.evening_intelligence_central_thread_id') : ($route['duty_thread_id'] ?? null),
                    'due' => $due,
                    'status' => $this->missingStatus($due, $now, $graceMinutes, $monitorStartedAt),
                    'reason' => $route === null || ! $route['valid'] ? 'Маршрут района не настроен полностью' : null,
                ];
            }
        }

        if ($journalAvailable && $keys !== []) {
            $actual = TelegramOutboundOperation::query()->whereIn('operation_key', $keys)->get()->keyBy('operation_key');
            foreach ($slots as &$slot) {
                $operation = $actual->get($slot['key']);
                if ($operation !== null) {
                    $slot['status'] = $operation->status === 'pending' && $operation->attempted_at?->lt($now->subMinutes(10))
                        ? 'uncertain' : $operation->status;
                    $slot['reason'] = $operation->error_reason;
                }
            }
            unset($slot);
        }

        if (! Schema::hasTable('telegram_scheduled_messages') || ! Schema::hasTable('telegram_scheduled_message_deliveries')) {
            return $slots;
        }

        $templates = TelegramScheduledMessage::query()->enabled()->with(['telegramChat', 'telegramTopic'])->get();
        $deliveries = TelegramScheduledMessageDelivery::query()
            ->where('scheduled_for', '>=', $date.' 00:00:00')
            ->where('scheduled_for', '<', CarbonImmutable::parse($date, 'Europe/Rome')->addDay()->toDateString().' 00:00:00')
            ->get();
        $deliveriesByMessage = $deliveries->groupBy('scheduled_message_id');
        $seenDeliveryIds = [];
        foreach ($templates as $template) {
            $time = ScheduledControlTypes::scheduledTime((string) $template->control_type, (string) $template->send_time);
            if ($time === null) {
                continue; // Conditional follow-up is counted below only when its delivery exists.
            }
            $due = CarbonImmutable::parse($date.' '.$time, 'Europe/Rome');
            if (! in_array($due->dayOfWeekIso, array_map('intval', $template->weekdays ?? []), true)) {
                continue;
            }
            $delivery = $deliveriesByMessage->get($template->getKey())?->first();
            if ($delivery !== null) {
                $seenDeliveryIds[] = $delivery->id;
            }
            $slots[] = [
                'key' => 'scheduled_control:'.$template->getKey().':'.$date,
                'type' => 'scheduled_control',
                'district' => null,
                'bot' => $delivery?->bot_type ?? (array_key_exists((string) $template->control_type, ScheduledControlTypes::LABELS) ? 'work' : 'scheduled'),
                'chat_id' => $delivery?->chat_id ?? $template->telegramChat?->telegram_chat_id,
                'thread_id' => $delivery?->message_thread_id ?? $template->telegramTopic?->telegram_thread_id,
                'due' => $due,
                'status' => $delivery?->status ?? $this->missingStatus($due, $now, $graceMinutes, $date.' 00:00:00'),
                'reason' => $delivery?->failure_reason,
            ];
        }
        foreach ($deliveries as $delivery) {
            if (in_array($delivery->id, $seenDeliveryIds, true)) {
                continue;
            }
            $slots[] = [
                'key' => 'scheduled_delivery:'.$delivery->id,
                'type' => 'scheduled_control', 'district' => null,
                'bot' => $delivery->bot_type ?? 'scheduled',
                'chat_id' => $delivery->chat_id, 'thread_id' => $delivery->message_thread_id,
                'due' => CarbonImmutable::parse($delivery->getRawOriginal('scheduled_for'), 'Europe/Rome'),
                'status' => $delivery->status, 'reason' => $delivery->failure_reason,
            ];
        }

        return $slots;
    }

    private function missingStatus(CarbonImmutable $due, CarbonImmutable $now, int $graceMinutes, ?string $monitorStartedAt): string
    {
        if (! $now->greaterThan($due->addMinutes($graceMinutes))) {
            return 'pending';
        }

        if ($monitorStartedAt === null || CarbonImmutable::parse($monitorStartedAt, 'Europe/Rome')->greaterThan($due)) {
            return 'unmonitored';
        }

        return 'missed';
    }

    private function existingDeliverySlots(string $date, CarbonImmutable $now, bool $journalAvailable): array
    {
        $slots = [];
        if (Schema::hasTable('telegram_scheduled_control_summary_deliveries')) {
            $summaries = TelegramScheduledControlSummaryDelivery::query()->whereDate('summary_date', $date)
                ->select(['id', 'chat_id', 'message_thread_id', 'status', 'sent_at'])->limit(11)->get();
            if ($summaries->count() > 10) {
                $this->sourceWarnings[] = 'Показаны только первые 10 итоговых доставок; счётчики неполные.';
                $summaries = $summaries->take(10);
            }
            foreach ($summaries as $summary) {
                $slots[] = [
                    'key' => 'scheduled_control_summary:'.$summary->id,
                    'type' => 'scheduled_control_summary', 'district' => null, 'bot' => 'analytics',
                    'chat_id' => $summary->chat_id, 'thread_id' => $summary->message_thread_id,
                    'due' => CarbonImmutable::parse($date.' '.config('services.telegram.scheduled_summary_cutoff', '22:00'), 'Europe/Rome'),
                    'status' => $summary->status, 'reason' => null,
                ];
            }

            if ($summaries->isEmpty() && config('services.telegram.scheduled_summary_enabled', false)
                && Schema::hasTable('telegram_scheduled_message_deliveries')) {
                $cutoff = CarbonImmutable::parse($date.' '.config('services.telegram.scheduled_summary_cutoff', '22:00'), 'Europe/Rome');
                $hasControls = TelegramScheduledMessageDelivery::query()
                    ->where('scheduled_for', '>=', $date.' 00:00:00')
                    ->where('scheduled_for', '<', $cutoff->addDay()->startOfDay()->format('Y-m-d H:i:s'))
                    ->exists();
                if ($hasControls) {
                    $slots[] = [
                        'key' => 'scheduled_control_summary:'.$date,
                        'type' => 'scheduled_control_summary', 'district' => null, 'bot' => 'analytics',
                        'chat_id' => config('services.telegram.scheduled_summary_chat_id'),
                        'thread_id' => config('services.telegram.scheduled_summary_thread_id'),
                        'due' => $cutoff, 'status' => $this->missingStatus($cutoff, $now, 15, $date.' 00:00:00'),
                        'reason' => 'Запись итоговой доставки не создана',
                    ];
                }
            }
        }

        if (Schema::hasTable('mobility_alert_messages')) {
            $messages = MobilityAlertMessage::query()->whereNotNull('delivery_key')
                ->where('created_at', '>=', $date.' 00:00:00')
                ->select(['id', 'chat_id', 'thread_id', 'sent_at', 'queued_at', 'created_at'])
                ->orderByDesc('id')->limit(101)->get();
            if ($messages->count() > 100) {
                $this->sourceWarnings[] = 'Показаны только последние 100 Mobility-доставок; счётчики неполные.';
                $messages = $messages->take(100);
            }
            $keys = $messages->map(fn ($message): string => 'mobility_alert_delivery:'.$message->id)->all();
            $actual = $journalAvailable && $keys !== []
                ? TelegramOutboundOperation::query()->whereIn('operation_key', $keys)->get()->keyBy('operation_key')
                : collect();
            foreach ($messages as $message) {
                $attempt = $actual->get('mobility_alert_delivery:'.$message->id);
                $due = CarbonImmutable::parse($message->queued_at ?? $message->created_at)->setTimezone('Europe/Rome');
                $slots[] = [
                    'key' => 'mobility_alert_delivery:'.$message->id,
                    'type' => 'mobility_alert', 'district' => null, 'bot' => 'analytics',
                    'chat_id' => $message->chat_id, 'thread_id' => $message->thread_id,
                    'due' => $due,
                    'status' => $message->sent_at ? 'sent' : ($attempt?->status ?? ($now->greaterThan($due->addMinutes(10)) ? 'uncertain' : 'pending')),
                    'reason' => $attempt?->error_reason,
                ];
            }
        }

        return $slots;
    }
}
