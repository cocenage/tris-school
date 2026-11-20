<?php

namespace App\Jobs;

use App\Models\MobilityAlertMessage;
use App\Services\Telegram\TelegramBotService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
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

    public function middleware(): array
    {
        $alertId = MobilityAlertMessage::query()->whereKey($this->messageId)->value('mobility_alert_id');

        return $alertId === null ? [] : [new WithoutOverlapping((string) $alertId, 10, 60)];
    }

    public function handle(TelegramBotService $bot): void
    {
        // Claim in a short transaction. Never keep SQLite's write lock during
        // the Telegram HTTP request; uncertain results are not retried blindly.
        $claimed = DB::transaction(function (): bool {
            $message = MobilityAlertMessage::query()->lockForUpdate()->find($this->messageId);
            if (! $message || $message->sent_at || $message->deleted_at
                || in_array($message->delivery_status, ['sent', 'uncertain', 'superseded'], true)) {
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

            if ($this->topicDeliveries($message)->where('id', '>', $message->id)->exists()) {
                $message->forceFill(['delivery_status' => 'superseded'])->save();

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

        $existingId = $this->topicDeliveries($message)
            ->whereNull('deleted_at')
            ->where('telegram_message_id', '!=', '')
            ->latest('id')
            ->value('telegram_message_id');

        try {
            if ($existingId !== null) {
                $delivered = $bot->editAnalyticsMessage($message->chat_id, $existingId, $message->text);
            } else {
                $telegramId = $bot->sendAnalyticsMessage($message->chat_id, $message->text, $message->thread_id ?? '');
                $delivered = is_int($telegramId) && $telegramId > 0;
            }
        } catch (\Throwable $error) {
            $this->markUncertain($message, class_basename($error));
            Log::warning('Mobility strike delivery result is uncertain.', [
                'reservation_id' => $message->id,
                'error_class' => class_basename($error),
            ]);

            return;
        }

        if (! $delivered) {
            $this->markUncertain($message, $existingId === null ? 'telegram_receipt_missing' : 'telegram_edit_not_confirmed');
            Log::warning('Mobility strike delivery returned no Telegram receipt.', [
                'reservation_id' => $message->id,
            ]);

            return;
        }

        $message->forceFill([
            // The original delivery owns the Telegram ID; this row records the edit.
            'telegram_message_id' => (string) ($telegramId ?? ''),
            'sent_at' => now(),
            'delivery_status' => 'sent',
            'error_code' => null,
        ])->save();
        Log::info('Mobility strike delivered', ['reservation_id' => $message->id, 'sent' => 1]);
    }

    private function topicDeliveries(MobilityAlertMessage $message): Builder
    {
        return MobilityAlertMessage::query()
            ->where('mobility_alert_id', $message->mobility_alert_id)
            ->where('message_type', $message->message_type)
            ->where('chat_id', $message->chat_id)
            ->where('thread_id', $message->thread_id)
            ->whereNotNull('sent_at');
    }

    private function markUncertain(MobilityAlertMessage $message, string $errorCode): void
    {
        $message->forceFill([
            'delivery_status' => 'uncertain',
            'error_code' => Str::limit(preg_replace('/[^A-Za-z0-9_]+/', '_', $errorCode), 80, ''),
        ])->save();
    }
}
