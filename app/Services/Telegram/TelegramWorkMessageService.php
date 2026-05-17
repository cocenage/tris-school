<?php

namespace App\Services\Telegram;

use App\Models\TelegramWorkMessage;
use Carbon\Carbon;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

class TelegramWorkMessageService
{
    public function handleUpdate(array $update): void
    {
        $message = $update['message']
            ?? $update['edited_message']
            ?? null;

        if (!$message) {
            return;
        }

        $chat = $message['chat'] ?? [];
        $chatType = $chat['type'] ?? null;

        if (!in_array($chatType, ['group', 'supergroup'], true)) {
            return;
        }

        $messageId = $message['message_id'] ?? null;
        $chatId = $chat['id'] ?? null;

$allowedChatId = config('services.telegram.work_allowed_chat_id');

if (
    $allowedChatId &&
    (string) $chatId !== (string) $allowedChatId
) {
    return;
}

        if (!$messageId || !$chatId) {
            return;
        }

        $text = $message['text']
            ?? $message['caption']
            ?? null;

        if (!$text) {
            return;
        }

        $from = $message['from'] ?? [];

        try {
            TelegramWorkMessage::updateOrCreate(
                [
                    'chat_id' => (string) $chatId,
                    'message_id' => (int) $messageId,
                ],
                [
                    'chat_title' => $chat['title'] ?? null,

                    'thread_id' => $message['message_thread_id'] ?? null,

                    'telegram_user_id' => isset($from['id']) ? (string) $from['id'] : null,
                    'username' => $from['username'] ?? null,
                    'first_name' => $from['first_name'] ?? null,
                    'last_name' => $from['last_name'] ?? null,

                    'reply_to_message_id' => Arr::get($message, 'reply_to_message.message_id'),

                    'text' => $text,
                    'raw' => $update,

                    'sent_at' => isset($message['date'])
                        ? Carbon::createFromTimestamp($message['date'])
                        : now(),
                ]
            );
        } catch (\Throwable $e) {
            Log::error('Telegram work message save failed', [
                'error' => $e->getMessage(),
                'update' => $update,
            ]);
        }
    }
}