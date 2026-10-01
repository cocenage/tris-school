<?php

namespace App\Jobs;

use App\Models\MobilityAlertMessage;
use App\Services\Telegram\TelegramBotService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class DeliverMobilityAlert implements ShouldQueue
{
    use Queueable;

    public int $tries = 8;

    public int $timeout = 30;

    public function __construct(public int $messageId) {}

    public function backoff(): array
    {
        return [60, 120, 300, 600];
    }

    public function handle(TelegramBotService $bot): void
    {
        // Serialize duplicate/recovered jobs against the durable reservation.
        DB::transaction(function () use ($bot): void {
            $message = MobilityAlertMessage::query()->lockForUpdate()->find($this->messageId);
            if (! $message || $message->sent_at || $message->deleted_at) {
                return;
            }
            $id = $bot->sendAnalyticsMessage($message->chat_id, $message->text, $message->thread_id ?? '');
            if (! $id) {
                Log::warning('Mobility strike delivery failed', ['reservation_id' => $message->id, 'failed' => 1]);
                throw new RuntimeException('Mobility Telegram delivery did not return a message ID.');
            }
            $message->forceFill(['telegram_message_id' => (string) $id, 'sent_at' => now()])->save();
            Log::info('Mobility strike delivered', ['reservation_id' => $message->id, 'sent' => 1]);
        });
    }
}
