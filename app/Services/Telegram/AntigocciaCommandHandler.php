<?php

namespace App\Services\Telegram;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

class AntigocciaCommandHandler
{
    public function isCommand(array $message): bool
    {
        if (! is_string($message['text'] ?? null)) {
            return false;
        }
        if (! preg_match('/^\/antigoccia(?:@([A-Za-z0-9_]{5,32}))?(?:\s|$)/i', trim($message['text']), $matches)) {
            return false;
        }

        if (! empty($matches[1])) {
            $botUsername = ltrim((string) config('services.telegram.bot_username', ''), '@');
            return $botUsername !== '' && strcasecmp($matches[1], $botUsername) === 0;
        }
        return true;
    }

    public function handle(array $message): JsonResponse
    {
        $chatId = (string) data_get($message, 'chat.id', '');
        $messageId = data_get($message, 'message_id');
        if ($chatId === '' || ! is_numeric($messageId)) {
            return response()->json(['ok' => true, 'skipped' => 'missing_address']);
        }

        $key = 'antigoccia:command:'.hash('sha256', $chatId.':'.$messageId);
        if (! Cache::add($key, true, now()->addDay())) {
            return response()->json(['ok' => true, 'skipped' => 'duplicate_command']);
        }

        try {
            $text = "🧪 <b>ANTIGOCCIA — Антикапля</b>\n\nЗаполните анкету после уборки. Квартиру и дату можно выбрать вручную, в том числе задним числом.";
            $markup = ['inline_keyboard' => [[
                ['text' => '🧪 Заполнить анкету', 'url' => route('antigoccia.index')],
            ]]];
            $sent = app(TelegramBotService::class)->sendMessage(
                $chatId,
                $text,
                data_get($message, 'message_thread_id') !== null ? (string) data_get($message, 'message_thread_id') : null,
                (string) $messageId,
                $markup,
            );

            if (! $sent) {
                Cache::forget($key);
                return response()->json(['ok' => false, 'error' => 'send_failed'], 500);
            }
        } catch (\Throwable $e) {
            Cache::forget($key);
            throw $e;
        }

        return response()->json(['ok' => true, 'antigoccia' => 'sent']);
    }
}
