<?php

namespace App\Jobs;

use App\Models\TelegramScheduledControlSummaryDelivery;
use App\Services\Telegram\TelegramBotService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class DeliverScheduledControlSummary implements ShouldQueue
{
    use Queueable;

    public int $tries = 8;

    public int $timeout = 30;

    public function __construct(public int $deliveryId) {}

    public function backoff(): array
    {
        return [30, 60, 120, 300, 600, 600, 600];
    }

    public function handle(TelegramBotService $telegram): void
    {
        try {
            DB::transaction(function () use ($telegram): void {
                $delivery = TelegramScheduledControlSummaryDelivery::query()->lockForUpdate()->find($this->deliveryId);
                if (! $delivery || $delivery->sent_at) {
                    return;
                }
                try {
                    $messageId = $telegram->sendAnalyticsMessage($delivery->chat_id, $delivery->text, $delivery->message_thread_id ?? '');
                } catch (Throwable) {
                    // Transport exception URLs can contain the bot token.
                    throw new RuntimeException('Scheduled control summary transport failed.');
                }
                if (! $messageId) {
                    throw new RuntimeException('Scheduled control summary has no confirmed Telegram receipt.');
                }
                $delivery->update(['status' => 'sent', 'sent_at' => now(), 'telegram_message_id' => $messageId]);
                DB::afterCommit(fn () => Log::info('scheduled_control_summary_sent', ['summary_delivery_id' => $delivery->id]));
            });
        } catch (Throwable $error) {
            TelegramScheduledControlSummaryDelivery::query()->whereKey($this->deliveryId)->whereNull('sent_at')->update(['status' => 'retrying']);
            Log::warning('scheduled_control_summary_failed', ['summary_delivery_id' => $this->deliveryId, 'exception' => class_basename($error)]);

            throw $error;
        }
    }

    public function failed(Throwable $error): void
    {
        TelegramScheduledControlSummaryDelivery::query()->whereKey($this->deliveryId)->whereNull('sent_at')->update(['status' => 'failed']);
        Log::warning('scheduled_control_summary_failed', ['summary_delivery_id' => $this->deliveryId, 'exhausted' => true]);
    }
}
