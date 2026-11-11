<?php

namespace App\Jobs;

use App\Models\MobilityAlertMessage;
use App\Services\Telegram\TelegramBotService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

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
        // Claim in a short transaction. Never keep SQLite's write lock during
        // the Telegram HTTP request; uncertain results are not retried blindly.
        $claimed = DB::transaction(function (): bool {
            $message = MobilityAlertMessage::query()->lockForUpdate()->find($this->messageId);
            if (! $message || $message->sent_at || $message->deleted_at
                || in_array($message->delivery_status, ['sent', 'uncertain'], true)) {
                return false;
            }

            if ($message->delivery_status === 'sending') {
                if ($message->attempted_at?->lte(now()->subMinutes(2))) {
                    $message->forceFill([
                        'delivery_status' => 'uncertain',
                        'error_code' => 'worker_interrupted_during_send',
                    ])->save();
                }

                return false;
            }

            $message->forceFill([
                'delivery_status' => 'sending',
                'attempted_at' => now(),
                'error_code' => null,
            ])->save();

            return true;
        });

        if (! $claimed) {
            return;
        }

        $message = MobilityAlertMessage::find($this->messageId);
        if (! $message) {
            return;
        }

        try {
            $id = $bot->sendAnalyticsMessage($message->chat_id, $message->text, $message->thread_id ?? '');
        } catch (\Throwable $error) {
            $this->markUncertain($message, class_basename($error));
            Log::warning('Mobility strike delivery result is uncertain.', [
                'reservation_id' => $message->id,
                'error_class' => class_basename($error),
            ]);

            return;
        }

        if (! $id) {
            $this->markUncertain($message, 'telegram_receipt_missing');
            Log::warning('Mobility strike delivery returned no Telegram receipt.', [
                'reservation_id' => $message->id,
            ]);

            return;
        }

        $message->forceFill([
            'telegram_message_id' => (string) $id,
            'sent_at' => now(),
            'delivery_status' => 'sent',
            'error_code' => null,
        ])->save();
        Log::info('Mobility strike delivered', ['reservation_id' => $message->id, 'sent' => 1]);
    }

    private function markUncertain(MobilityAlertMessage $message, string $errorCode): void
    {
        $message->forceFill([
            'delivery_status' => 'uncertain',
            'error_code' => Str::limit(preg_replace('/[^A-Za-z0-9_]+/', '_', $errorCode), 80, ''),
        ])->save();
    }
}
