<?php

namespace App\Services\Telegram;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TelegramBotService
{
    public function sendMessage(string $chatId, string $text, ?string $threadId = null): void
    {
        $token = config('services.telegram.bot_token');

        if (! $token) {
            Log::warning('Telegram bot token is not configured.');
            return;
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

        Http::timeout(5)
    ->connectTimeout(3)
    ->post("https://api.telegram.org/bot{$token}/sendMessage", $payload);
    }
}