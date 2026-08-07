<?php

namespace App\Services\Telegram;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TelegramBotService
{
    public function sendMessage(
        string $chatId,
        string $text,
        ?string $threadId = null,
        ?string $replyToMessageId = null,
    ): ?int
    {
        $token = config('services.telegram.bot_token');

        if (!$token) {
            Log::warning('Telegram bot token is not configured.');
            return null;
        }

        $payload = [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
        ];

        if ($threadId) {
            $payload['message_thread_id'] = $threadId;
        }

        if ($replyToMessageId) {
            $payload['reply_to_message_id'] = (int) $replyToMessageId;
            $payload['allow_sending_without_reply'] = true;
        }

        $response = Http::timeout(5)
            ->connectTimeout(3)
            ->post("https://api.telegram.org/bot{$token}/sendMessage", $payload);

        Log::info('Telegram sendMessage response', [
            'status' => $response->status(),
            'body' => $response->body(),
            'payload' => $payload,
        ]);

        return $response->successful()
            ? data_get($response->json(), 'result.message_id')
            : null;
    }
}
