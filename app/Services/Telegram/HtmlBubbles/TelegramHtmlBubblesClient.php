<?php

namespace App\Services\Telegram\HtmlBubbles;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class TelegramHtmlBubblesClient
{
    /** @return array<string, mixed> */
    public function sendWidget(string $token, array $parameters): array
    {
        $result = $this->request($token, 'sendWidget', $parameters);

        if (! is_array($result)) {
            throw new RuntimeException('telegram_htmlbubbles_invalid_send_result');
        }

        return $result;
    }

    public function answerWidgetQuery(string $token, string $queryId, string $result): void
    {
        $this->request($token, 'answerWidgetQuery', [
            'widget_query_id' => $queryId,
            'result' => $result,
        ]);
    }

    public function editMessageWidgetData(string $token, string $chatId, int $messageId, string $chatData): void
    {
        $this->request($token, 'editMessageWidgetData', [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'chat_data' => $chatData,
        ]);
    }

    private function request(string $token, string $method, array $parameters): mixed
    {
        if (trim($token) === '') {
            throw new RuntimeException('telegram_htmlbubbles_token_missing');
        }

        $response = Http::withOptions([
            'force_ip_resolve' => config('services.telegram.ip_resolve', 'v6'),
        ])->connectTimeout(3)
            ->timeout(8)
            ->post($this->endpoint($token, $method), $parameters);

        if (! $this->isSuccessful($response)) {
            throw new RuntimeException('telegram_htmlbubbles_api_failed');
        }

        return data_get($response->json(), 'result');
    }

    private function isSuccessful(Response $response): bool
    {
        return $response->successful() && data_get($response->json(), 'ok') === true;
    }

    private function endpoint(string $token, string $method): string
    {
        return 'https://api.telegram.org/beta/bot'.$token.'/test/'.$method;
    }
}
