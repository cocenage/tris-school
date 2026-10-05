<?php

namespace App\Services\Telegram;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

class TelegramUpdatePoller
{
    /**
     * @param  list<string>  $allowedUpdates
     * @param  callable(array<string, mixed>): void  $processor
     * @return array{processed: int}
     *
     * @throws TelegramPollingFailure
     */
    public function poll(string $token, array $allowedUpdates, int $limit, callable $processor): array
    {
        $updates = $this->fetch($token, $allowedUpdates, ['limit' => $limit], 'fetch');
        $processed = $this->processUpdates($updates, $processor);

        if ($processed['last_update_id'] === null) {
            return ['processed' => 0];
        }

        // Supplying the next offset acknowledges the completed batch. Telegram
        // may return another update in this same request, so process it too.
        $nextUpdates = $this->fetch($token, $allowedUpdates, [
            'offset' => $processed['last_update_id'] + 1,
            'limit' => 1,
        ], 'acknowledge');
        $next = $this->processUpdates($nextUpdates, $processor);

        return ['processed' => $processed['count'] + $next['count']];
    }

    /**
     * @param  list<string>  $allowedUpdates
     * @param  array<string, int>  $parameters
     * @return list<array<string, mixed>>
     *
     * @throws TelegramPollingFailure
     */
    private function fetch(string $token, array $allowedUpdates, array $parameters, string $stage): array
    {
        try {
            $response = Http::timeout(10)
                ->connectTimeout(3)
                ->post('https://api.telegram.org/bot'.$token.'/getUpdates', [
                    ...$parameters,
                    'timeout' => 0,
                    'allowed_updates' => $allowedUpdates,
                ]);

            $this->assertSuccessfulResponse($response);
            $updates = data_get($response->json(), 'result');

            if (! is_array($updates)) {
                throw new RuntimeException('telegram_invalid_result');
            }

            foreach ($updates as $update) {
                if (! is_array($update)) {
                    throw new RuntimeException('telegram_invalid_update');
                }
            }

            return array_values($updates);
        } catch (Throwable $error) {
            throw new TelegramPollingFailure($stage, previous: $error);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $updates
     * @param  callable(array<string, mixed>): void  $processor
     * @return array{count: int, last_update_id: int|null}
     *
     * @throws TelegramPollingFailure
     */
    private function processUpdates(array $updates, callable $processor): array
    {
        usort($updates, fn (array $left, array $right): int => ((int) ($left['update_id'] ?? -1)) <=> ((int) ($right['update_id'] ?? -1)));

        $lastSuccessfulUpdateId = null;
        $processed = 0;

        foreach ($updates as $update) {
            $updateId = $update['update_id'] ?? null;

            if (! is_numeric($updateId)) {
                throw new TelegramPollingFailure('process');
            }

            try {
                $processor($update);
            } catch (Throwable $error) {
                throw new TelegramPollingFailure('process', (int) $updateId, $error);
            }

            $lastSuccessfulUpdateId = (int) $updateId;
            $processed++;
        }

        return ['count' => $processed, 'last_update_id' => $lastSuccessfulUpdateId];
    }

    private function assertSuccessfulResponse(Response $response): void
    {
        if (! $response->successful() || data_get($response->json(), 'ok') !== true) {
            throw new RuntimeException('telegram_http_failure', $response->status());
        }
    }
}
