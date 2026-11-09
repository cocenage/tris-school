<?php

namespace App\Services\Telegram;

use App\Jobs\DeliverScheduledControlSummary;
use App\Models\TelegramOutboundOperation;
use App\Models\TelegramScheduledControlSummaryDelivery;
use App\Models\TelegramScheduledMessage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class TelegramScheduledControlSummaryDeliveryService
{
    public function __construct(private TelegramScheduledControlSummaryBuilder $builder, private TelegramScheduledControlSummaryRichFormatter $formatter) {}

    public function eligible(string $date, array $summary): bool
    {
        $now = CarbonImmutable::now('Europe/Rome');
        if ($date !== $now->toDateString()) {
            return false;
        }
        $cutoff = (string) config('services.telegram.scheduled_summary_cutoff', '22:00');
        if (! preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $cutoff)) {
            throw new RuntimeException('Invalid scheduled summary cutoff.');
        }
        if ($now->gte($now->startOfDay()->setTimeFromTimeString($cutoff))) {
            return true;
        }
        $window = max(1, (int) config('services.telegram.scheduled_response_window_minutes', 60))
            + max(0, (int) config('services.telegram.scheduled_summary_buffer_minutes', 5));
        $latest = null;
        foreach (TelegramScheduledMessage::query()->enabled()->get() as $control) {
            if (in_array($now->dayOfWeekIso, array_map('intval', $control->weekdays ?? []), true)) {
                $planned = $now->startOfDay()->setTimeFromTimeString($control->send_time)->addMinutes($window);
                $latest = $latest === null || $planned->gt($latest) ? $planned : $latest;
            }
        }
        foreach ($summary['deliveries'] as $delivery) {
            if (in_array($delivery['delivery_status'], ['pending', 'retrying'], true)) {
                return false;
            }
            $end = CarbonImmutable::parse($delivery['sent_at'] ?? $delivery['scheduled_for'])->addMinutes($window);
            $latest = $latest === null || $end->gt($latest) ? $end : $latest;
        }

        return $latest !== null && $now->gte($latest);
    }

    public function queue(string $date, bool $onlyIfDue = false): array
    {
        if ($onlyIfDue && ! config('services.telegram.scheduled_summary_enabled', false)) {
            return ['queued' => 0, 'skipped' => 'disabled'];
        }
        if ($this->builder->day($date)->gt(CarbonImmutable::now('Europe/Rome')->startOfDay())) {
            return ['queued' => 0, 'skipped' => 'future_date'];
        }

        $chatId = config('services.telegram.scheduled_summary_chat_id');
        $threadId = config('services.telegram.scheduled_summary_thread_id');
        $context = $this->operationContext($date, $chatId, $threadId);
        $journal = app(TelegramDigestBuildJournal::class);
        $journal->reserve($context);

        try {
            $summary = $this->builder->build($date);
        } catch (\Throwable $error) {
            $journal->record($context, 'controls_source', 'failure_unclassified');

            throw $error;
        }

        $controlCount = (int) $summary['totals']['controls'];
        $journal->record($context, 'controls_source', $controlCount > 0 ? 'loaded' : 'no_input', [
            'controls_count' => $controlCount,
        ]);
        $journal->record($context, 'controls_filter', $controlCount > 0 ? 'complete' : 'no_significant_events', [
            'controls_count' => $controlCount,
            'responses_count' => (int) ($summary['totals']['response_messages'] ?? 0),
        ]);

        if ($controlCount === 0) {
            $journal->record($context, 'controls_format', 'no_message', ['message_present' => false]);
            $journal->record($context, 'controls_queue', 'skipped', ['queued_count' => 0]);

            return ['queued' => 0, 'skipped' => 'no_deliveries'];
        }
        if ($onlyIfDue) {
            try {
                $eligible = $this->eligible($date, $summary);
            } catch (\Throwable $error) {
                $journal->record($context, 'controls_filter', 'failure_unclassified');

                throw $error;
            }

            if (! $eligible) {
                $journal->record($context, 'controls_queue', 'skipped', ['queued_count' => 0]);

                return ['queued' => 0, 'skipped' => 'not_due'];
            }
        }
        if (! filled($chatId)) {
            $journal->record($context, 'controls_queue', 'skipped', ['queued_count' => 0]);

            return ['queued' => 0, 'skipped' => 'destination_missing'];
        }

        $delivery = null;
        $dispatchFailed = false;
        try {
            $queued = DB::transaction(function () use ($date, $summary, $chatId, $threadId, $context, &$delivery, &$dispatchFailed): int {
                $richMessage = $this->formatter->richMessage($summary);
                $fallbackHtml = $this->formatter->fallbackHtml($summary);
                $storedSummary = $summary;
                $storedSummary['rendered_rich_message'] = $richMessage;
                $hash = hash('sha256', json_encode($storedSummary, JSON_THROW_ON_ERROR));
                $key = hash('sha256', implode('|', [$date, $chatId, $threadId ?? '']));
                $delivery = TelegramScheduledControlSummaryDelivery::firstOrCreate(['delivery_key' => $key], [
                    'summary_date' => $date, 'chat_id' => (string) $chatId, 'message_thread_id' => filled($threadId) ? (string) $threadId : null,
                    'summary_hash' => $hash, 'summary' => $storedSummary, 'text' => $fallbackHtml,
                ]);
                $delivery = TelegramScheduledControlSummaryDelivery::query()->lockForUpdate()->findOrFail($delivery->id);

                return $this->enqueue($delivery, $context['operation_key'], $dispatchFailed);
            });
            $this->linkOperationToDelivery($context, $delivery);
            $messagePresent = trim((string) $delivery->text) !== '';
            $journal->record($context, 'controls_format', $messagePresent ? 'built' : 'no_message', [
                'message_present' => $messagePresent,
            ]);
            $journal->record($context, 'controls_queue', $queued > 0 ? 'queued' : 'skipped', [
                'queued_count' => $queued,
            ]);
            Log::info('scheduled_control_summary_queued', ['queued' => $queued, 'date' => $date]);

            return ['queued' => $queued, 'skipped' => $queued ? null : 'already_reserved_or_sent'];
        } catch (\Throwable $error) {
            if ($dispatchFailed) {
                $journal->record($context, 'controls_queue', 'queue_failed');
            } else {
                $journal->record($context, 'controls_format', 'calculation_error');
            }
            Log::warning('scheduled_control_summary_failed', ['exception' => class_basename($error)]);

            return ['queued' => 0, 'failed' => 1];
        }
    }

    public function recoverPending(): int
    {
        $queued = 0;
        TelegramScheduledControlSummaryDelivery::query()->whereNull('sent_at')
            ->whereIn('status', ['queued', 'retrying'])
            ->where(fn ($query) => $query->whereNull('queued_at')->orWhere('queued_at', '<=', now()->subHour()))
            ->each(function ($delivery) use (&$queued): void {
                $delivery = TelegramScheduledControlSummaryDelivery::query()->findOrFail($delivery->id);
                $context = $this->operationContext($delivery->summary_date, $delivery->chat_id, $delivery->message_thread_id, $delivery->id);
                $journal = app(TelegramDigestBuildJournal::class);
                $journal->reserve($context);
                $dispatchFailed = false;

                try {
                    $count = DB::transaction(fn () => $this->enqueue(
                        TelegramScheduledControlSummaryDelivery::query()->lockForUpdate()->findOrFail($delivery->id),
                        $context['operation_key'],
                        $dispatchFailed,
                    ));
                } catch (\Throwable $error) {
                    if ($dispatchFailed) {
                        $journal->record($context, 'controls_queue', 'queue_failed');
                    }

                    throw $error;
                }

                $this->linkOperationToDelivery($context, $delivery);
                $journal->record($context, 'controls_queue', $count > 0 ? 'queued' : 'skipped', ['queued_count' => $count]);
                $queued += $count;
            });

        return $queued;
    }

    private function enqueue(TelegramScheduledControlSummaryDelivery $delivery, string $operationKey, bool &$dispatchFailed = false): int
    {
        if ($delivery->sent_at
            || in_array($delivery->status, ['sending', 'uncertain', 'failed'], true)
            || ($delivery->queued_at && $delivery->queued_at->gt(now()->subHour()))) {
            return 0;
        }
        $delivery->update(['queued_at' => now(), 'status' => 'queued']);
        $job = (new DeliverScheduledControlSummary($delivery->id, $operationKey))->onConnection('database')->onQueue('default')->afterCommit();
        try {
            Bus::dispatch($job);
        } catch (\Throwable $error) {
            $dispatchFailed = true;

            throw $error;
        }

        return 1;
    }

    private function operationContext(string $date, ?string $chatId, ?string $threadId, ?int $deliveryId = null): array
    {
        $destinationHash = hash('sha256', implode('|', [$date, $chatId ?? '', $threadId ?? '']));

        return [
            'operation_key' => 'scheduled_control_summary:'.$date.':'.$destinationHash,
            'notification_type' => 'scheduled_control_summary',
            'bot' => 'analytics',
            'chat_id' => $chatId,
            'message_thread_id' => $threadId,
            'source_type' => 'telegram_scheduled_control_summary_delivery',
            'source_id' => $deliveryId === null ? null : (string) $deliveryId,
            'scheduled_for' => $date,
        ];
    }

    private function linkOperationToDelivery(array $context, TelegramScheduledControlSummaryDelivery $delivery): void
    {
        try {
            TelegramOutboundOperation::query()
                ->where('operation_key', $context['operation_key'])
                ->update([
                    'source_type' => 'telegram_scheduled_control_summary_delivery',
                    'source_id' => (string) $delivery->id,
                    'updated_at' => now(),
                ]);
        } catch (\Throwable $exception) {
            Log::warning('Scheduled Controls outbound source link unavailable.', ['error' => class_basename($exception)]);
        }
    }
}
