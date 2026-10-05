<?php

namespace App\Services\Telegram;

use App\Jobs\DeliverScheduledControlSummary;
use App\Models\TelegramScheduledControlSummaryDelivery;
use App\Models\TelegramScheduledMessage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class TelegramScheduledControlSummaryDeliveryService
{
    public function __construct(private TelegramScheduledControlSummaryBuilder $builder, private TelegramScheduledControlSummaryFormatter $formatter) {}

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
        $summary = $this->builder->build($date);
        if ($summary['totals']['controls'] === 0) {
            return ['queued' => 0, 'skipped' => 'no_deliveries'];
        }
        if ($onlyIfDue && ! $this->eligible($date, $summary)) {
            return ['queued' => 0, 'skipped' => 'not_due'];
        }
        $chatId = config('services.telegram.scheduled_summary_chat_id');
        $threadId = config('services.telegram.scheduled_summary_thread_id');
        if (! filled($chatId)) {
            return ['queued' => 0, 'skipped' => 'destination_missing'];
        }
        try {
            $queued = DB::transaction(function () use ($date, $summary, $chatId, $threadId): int {
                $hash = hash('sha256', json_encode($summary, JSON_THROW_ON_ERROR));
                $key = hash('sha256', implode('|', [$date, $chatId, $threadId ?? '']));
                $delivery = TelegramScheduledControlSummaryDelivery::firstOrCreate(['delivery_key' => $key], [
                    'summary_date' => $date, 'chat_id' => (string) $chatId, 'message_thread_id' => filled($threadId) ? (string) $threadId : null,
                    'summary_hash' => $hash, 'summary' => $summary, 'text' => $this->formatter->format($summary),
                ]);
                $delivery = TelegramScheduledControlSummaryDelivery::query()->lockForUpdate()->findOrFail($delivery->id);

                return $this->enqueue($delivery);
            });
            Log::info('scheduled_control_summary_queued', ['queued' => $queued, 'date' => $date]);

            return ['queued' => $queued, 'skipped' => $queued ? null : 'already_reserved_or_sent'];
        } catch (\Throwable $error) {
            Log::warning('scheduled_control_summary_failed', ['exception' => class_basename($error)]);

            return ['queued' => 0, 'failed' => 1];
        }
    }

    public function recoverPending(): int
    {
        $queued = 0;
        TelegramScheduledControlSummaryDelivery::query()->whereNull('sent_at')
            ->where(fn ($query) => $query->whereNull('queued_at')->orWhere('queued_at', '<=', now()->subHour()))
            ->each(function ($delivery) use (&$queued): void {
                $queued += DB::transaction(fn () => $this->enqueue(TelegramScheduledControlSummaryDelivery::query()->lockForUpdate()->findOrFail($delivery->id)));
            });

        return $queued;
    }

    private function enqueue(TelegramScheduledControlSummaryDelivery $delivery): int
    {
        if ($delivery->sent_at || ($delivery->queued_at && $delivery->queued_at->gt(now()->subHour()))) {
            return 0;
        }
        $delivery->update(['queued_at' => now(), 'status' => 'queued']);
        $job = (new DeliverScheduledControlSummary($delivery->id))->onConnection('database')->onQueue('default')->afterCommit();
        Bus::dispatch($job);

        return 1;
    }
}
