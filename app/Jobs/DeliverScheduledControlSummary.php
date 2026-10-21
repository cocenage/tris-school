<?php

namespace App\Jobs;

use App\Models\TelegramScheduledControlSummaryDelivery;
use App\Services\Telegram\TelegramBotService;
use App\Services\Telegram\TelegramOutboundMonitor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class DeliverScheduledControlSummary implements ShouldQueue
{
    use Queueable;

    public int $tries = 8;

    public int $timeout = 30;

    public function __construct(
        public int $deliveryId,
    ) {}

    public function backoff(): array
    {
        return [30, 60, 120, 300, 600, 600, 600];
    }

    public function handle(TelegramBotService $telegram): void
    {
        $claimed = TelegramScheduledControlSummaryDelivery::query()
            ->whereKey($this->deliveryId)
            ->whereNull('sent_at')
            ->whereIn('status', ['queued', 'retrying'])
            ->update(['status' => 'sending', 'updated_at' => now()]);

        if (! $claimed) {
            return;
        }

        $delivery = TelegramScheduledControlSummaryDelivery::query()->find($this->deliveryId);

        if (! $delivery) {
            return;
        }

        try {
            $messageId = app(TelegramOutboundMonitor::class)->withContext([
                'operation_key' => 'scheduled_control_summary:'.$delivery->id,
                'notification_type' => 'scheduled_control_summary',
                'source_type' => 'telegram_scheduled_control_summary_delivery',
                'source_id' => $delivery->id,
            ], fn () => $telegram->sendAnalyticsMessage(
                $delivery->chat_id,
                $delivery->text,
                $delivery->message_thread_id ?? '',
            ));
        } catch (Throwable $error) {
            $this->markUncertain();
            Log::warning('scheduled_control_summary_outcome_uncertain', [
                'summary_delivery_id' => $delivery->id,
                'failure_mode' => 'transport_exception',
                'exception' => class_basename($error),
            ]);

            return;
        }

        if (! $messageId) {
            $this->markUncertain();
            Log::warning('scheduled_control_summary_outcome_uncertain', [
                'summary_delivery_id' => $delivery->id,
                'failure_mode' => 'no_confirmed_receipt',
            ]);

            return;
        }

        $updated = TelegramScheduledControlSummaryDelivery::query()
            ->whereKey($delivery->id)
            ->whereNull('sent_at')
            ->whereIn('status', ['sending', 'uncertain'])
            ->update([
                'status' => 'sent',
                'sent_at' => now(),
                'telegram_message_id' => $messageId,
                'updated_at' => now(),
            ]);

        if ($updated) {
            Log::info('scheduled_control_summary_sent', ['summary_delivery_id' => $delivery->id]);

            return;
        }

        Log::warning('scheduled_control_summary_receipt_not_persisted', [
            'summary_delivery_id' => $delivery->id,
        ]);
    }

    public function failed(Throwable $error): void
    {
        $delivery = TelegramScheduledControlSummaryDelivery::query()
            ->whereKey($this->deliveryId)
            ->whereNull('sent_at')
            ->first();

        if (! $delivery) {
            return;
        }

        $status = $delivery->status === 'sending' ? 'uncertain' : 'retrying';
        $updated = TelegramScheduledControlSummaryDelivery::query()
            ->whereKey($delivery->id)
            ->whereNull('sent_at')
            ->where('status', $delivery->status)
            ->update(['status' => $status, 'updated_at' => now()]);

        if (! $updated) {
            return;
        }

        Log::warning('scheduled_control_summary_failed', [
            'summary_delivery_id' => $delivery->id,
            'outcome' => $status,
            'exception' => class_basename($error),
            'exhausted' => true,
        ]);
    }

    private function markUncertain(): void
    {
        TelegramScheduledControlSummaryDelivery::query()
            ->whereKey($this->deliveryId)
            ->whereNull('sent_at')
            ->where('status', 'sending')
            ->update(['status' => 'uncertain', 'updated_at' => now()]);
    }
}
