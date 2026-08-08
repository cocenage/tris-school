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
        ?array $replyMarkup = null,
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

        if ($replyMarkup !== null) {
            $payload['reply_markup'] = $replyMarkup;
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

    /**
     * Send a structured Rich Message and transparently fall back to the
     * existing HTML message when the Bot API or the current chat rejects it.
     */
    public function sendRichMessage(
        string $chatId,
        array $richMessage,
        string $fallbackHtml,
        ?string $threadId = null,
        ?string $replyToMessageId = null,
        ?array $replyMarkup = null,
    ): ?int {
        $token = config('services.telegram.bot_token');

        if (!$token) {
            Log::warning('Telegram bot token is not configured.');

            return null;
        }

        if (! config('services.telegram.rich_messages_enabled', false)) {
            return $this->sendMessage(
                chatId: $chatId,
                text: $fallbackHtml,
                threadId: $threadId,
                replyToMessageId: $replyToMessageId,
                replyMarkup: $replyMarkup,
            );
        }

        $payload = [
            'chat_id' => $chatId,
            'rich_message' => $richMessage,
            'disable_web_page_preview' => true,
        ];

        $this->addMessageOptions($payload, $threadId, $replyToMessageId, $replyMarkup);

        try {
            $response = Http::timeout(10)
                ->connectTimeout(3)
                ->post("https://api.telegram.org/bot{$token}/sendRichMessage", $payload);

            if ($response->successful() && data_get($response->json(), 'ok', true)) {
                return data_get($response->json(), 'result.message_id');
            }

            Log::warning('Telegram Rich Message rejected; using HTML fallback.', [
                'status' => $response->status(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Telegram Rich Message failed; using HTML fallback.', [
                'message' => $e->getMessage(),
            ]);
        }

        $fallbackPayload = [
            'chat_id' => $chatId,
            'text' => $fallbackHtml,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
        ];

        $this->addMessageOptions($fallbackPayload, $threadId, $replyToMessageId, $replyMarkup);

        $fallbackResponse = Http::timeout(10)
            ->connectTimeout(3)
            ->post("https://api.telegram.org/bot{$token}/sendMessage", $fallbackPayload);

        return $fallbackResponse->successful()
            ? data_get($fallbackResponse->json(), 'result.message_id')
            : null;
    }

    /**
     * Edit an existing message as Rich Message, falling back to HTML text.
     */
    public function editMessage(
        string $chatId,
        int|string $messageId,
        array $richMessage,
        string $fallbackHtml,
        ?array $replyMarkup = null,
        bool $forceLegacy = false,
    ): bool {
        $token = config('services.telegram.bot_token');

        if (!$token) {
            return false;
        }

        if ($forceLegacy || ! config('services.telegram.rich_messages_enabled', false)) {
            return $this->editHtmlMessage(
                token: $token,
                chatId: $chatId,
                messageId: $messageId,
                html: $fallbackHtml,
                replyMarkup: $replyMarkup,
            );
        }

        $richPayload = [
            'chat_id' => $chatId,
            'message_id' => (int) $messageId,
            'rich_message' => $richMessage,
        ];

        if ($replyMarkup !== null) {
            $richPayload['reply_markup'] = $replyMarkup;
        }

        try {
            $response = Http::timeout(10)
                ->connectTimeout(3)
                ->post("https://api.telegram.org/bot{$token}/editMessageText", $richPayload);

            if ($response->successful() && data_get($response->json(), 'ok', true)) {
                return true;
            }
        } catch (\Throwable $e) {
            Log::warning('Telegram Rich Message edit failed; using HTML fallback.', [
                'message' => $e->getMessage(),
            ]);
        }

        $fallbackPayload = [
            'chat_id' => $chatId,
            'message_id' => (int) $messageId,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
            'text' => $fallbackHtml,
        ];

        if ($replyMarkup !== null) {
            $fallbackPayload['reply_markup'] = $replyMarkup;
        }

        $fallbackResponse = Http::timeout(10)
            ->connectTimeout(3)
            ->post("https://api.telegram.org/bot{$token}/editMessageText", $fallbackPayload);

        return $fallbackResponse->successful();
    }

    private function editHtmlMessage(
        string $token,
        string $chatId,
        int|string $messageId,
        string $html,
        ?array $replyMarkup = null,
    ): bool {
        $payload = [
            'chat_id' => $chatId,
            'message_id' => (int) $messageId,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
            'text' => $html,
        ];

        if ($replyMarkup !== null) {
            $payload['reply_markup'] = $replyMarkup;
        }

        return Http::timeout(10)
            ->connectTimeout(3)
            ->post("https://api.telegram.org/bot{$token}/editMessageText", $payload)
            ->successful();
    }

    private function addMessageOptions(
        array &$payload,
        ?string $threadId,
        ?string $replyToMessageId,
        ?array $replyMarkup,
    ): void {
        if ($threadId) {
            $payload['message_thread_id'] = (int) $threadId;
        }

        if ($replyToMessageId) {
            $payload['reply_parameters'] = [
                'message_id' => (int) $replyToMessageId,
                'allow_sending_without_reply' => true,
            ];
        }

        if ($replyMarkup !== null) {
            $payload['reply_markup'] = $replyMarkup;
        }
    }
}
