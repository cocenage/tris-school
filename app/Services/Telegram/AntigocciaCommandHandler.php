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
        $text = trim($message['text']);
        if (preg_match('/^@([A-Za-z0-9_]{5,32})\s+\/antigoccia(?:\s|$)/i', $text, $matches) !== 1
            && preg_match('/^\/antigoccia(?:@([A-Za-z0-9_]{5,32}))?(?:\s|$)/i', $text, $matches) !== 1) {
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

        $url = TelegramOksRichCard::miniAppUrl('antigoccia');
        if ($url === null) {
            return response()->json(['ok' => false, 'error' => 'bot_username_missing'], 503);
        }

        $key = 'antigoccia:command:'.hash('sha256', $chatId.':'.$messageId);
        if (! Cache::add($key, true, now()->addDay())) {
            return response()->json(['ok' => true, 'skipped' => 'duplicate_command']);
        }

        try {
            $text = 'АНКЕТА «АНТИКАПЛЯ» (ANTIGOCCIA)';
            $markup = ['inline_keyboard' => [[
                ['text' => 'Заполнить', 'url' => $url],
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

    public function handleInline(array $query): JsonResponse
    {
        $queryId = $query['id'] ?? null;
        if (! is_string($queryId) || $queryId === '') {
            return response()->json(['ok' => false, 'error' => 'missing_inline_query_id'], 400);
        }

        $url = TelegramOksRichCard::miniAppUrl('antigoccia');
        $search = mb_strtolower(trim((string) ($query['query'] ?? '')));
        $results = [];
        if ($url !== null && in_array($search, ['', 'antigoccia', '/antigoccia', 'антикапля'], true)) {
            $results[] = [
                'type' => 'article',
                'id' => 'antigoccia',
                'title' => 'АНКЕТА «АНТИКАПЛЯ» (ANTIGOCCIA)',
                'description' => 'Открыть анкету после уборки',
                'input_message_content' => ['message_text' => 'АНКЕТА «АНТИКАПЛЯ» (ANTIGOCCIA)'],
                'reply_markup' => ['inline_keyboard' => [[['text' => 'Заполнить', 'url' => $url]]]],
            ];
        }

        $sent = app(TelegramBotService::class)->answerInlineQuery($queryId, $results);

        return response()->json(['ok' => $sent], $sent ? 200 : 500);
    }
}
