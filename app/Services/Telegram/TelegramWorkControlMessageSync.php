<?php

namespace App\Services\Telegram;

use App\Models\TelegramScheduledMessageDelivery;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class TelegramWorkControlMessageSync
{
    public function sync(TelegramScheduledMessageDelivery $delivery): bool
    {
        if ($delivery->bot_type !== 'work' || $delivery->status !== 'sent' || ! $delivery->telegram_message_id) {
            return false;
        }

        try {
            $edited = Cache::lock('telegram_work_control_sync:'.$delivery->id, 10)->block(3, function () use ($delivery): bool {
                $current = $delivery->fresh();
                if ($current === null) {
                    return false;
                }

                $payload = app(TelegramScheduledControlSummaryFormatter::class)->interactivePayload($current);
                if (is_string($current->render_hash) && hash_equals($current->render_hash, $payload['render_hash'])) {
                    return true;
                }

                $edited = app(TelegramBotService::class)->editMessage(
                    (string) $current->chat_id, (int) $current->telegram_message_id, [], $payload['text'],
                    $payload['reply_markup'], true,
                );

                if ($edited) {
                    $current->update(['render_hash' => $payload['render_hash']]);
                }

                return $edited;
            });
        } catch (Throwable $exception) {
            Log::warning('Work control message sync failed.', ['delivery_id' => $delivery->id, 'exception' => class_basename($exception)]);

            return false;
        }

        if (! $edited) {
            Log::warning('Work control message edit was rejected.', ['delivery_id' => $delivery->id]);
        }

        return $edited;
    }
}
