<?php

namespace App\Services\Telegram;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class TelegramUpdatePoller
{
    private const MAX_CONNECTION_RETRIES = 1;

    private const CONNECTION_RETRY_BACKOFF_MICROSECONDS = 250_000;

    /**
     * @param  list<string>  $allowedUpdates
     * @param  callable(array<string, mixed>): void  $processor
     * @return array{processed: int, network_retries: int, last_update_id?: int}
     *
     * @throws TelegramPollingFailure
     */
    public function poll(
        string $token,
        array $allowedUpdates,
        int $limit,
        callable $processor,
        ?int $offset = null,
        int $waitSeconds = 0,
        string $pollerName = 'unknown',
        bool $experimentalWidgets = false,
    ): array {
        $pollerName = in_array($pollerName, ['work', 'analytics', 'scheduled'], true) ? $pollerName : 'unknown';
        $parameters = ['limit' => $limit];
        $connectionRetries = 0;

        if ($offset !== null) {
            $parameters['offset'] = $offset;
        }

        $updates = $this->fetch($token, $allowedUpdates, $parameters, 'fetch', $pollerName, $connectionRetries, $waitSeconds, $experimentalWidgets);
        $processed = $this->processUpdates($updates, $processor);

        if ($processed['last_update_id'] === null) {
            $result = [
                'processed' => 0,
                'network_retries' => $connectionRetries,
            ];

            if ($waitSeconds > 0 && $offset !== null) {
                $result['last_update_id'] = $offset - 1;
            }

            return $result;
        }

        // Supplying the next offset acknowledges only the completed batch.
        // Keep that exact offset if the response is lost and the request retries.
        $nextUpdates = $this->fetch($token, $allowedUpdates, [
            'offset' => $processed['last_update_id'] + 1,
            'limit' => 1,
        ], 'acknowledge', $pollerName, $connectionRetries, experimentalWidgets: $experimentalWidgets);
        $next = $this->processUpdates($nextUpdates, $processor);

        $result = [
            'processed' => $processed['count'] + $next['count'],
            'network_retries' => $connectionRetries,
        ];

        if ($waitSeconds > 0) {
            $result['last_update_id'] = $next['last_update_id'] ?? $processed['last_update_id'];
        }

        return $result;
    }

    /**
     * @param  list<string>  $allowedUpdates
     * @param  array<string, int>  $parameters
     * @return list<array<string, mixed>>
     *
     * @throws TelegramPollingFailure
     */
    private function fetch(
        string $token,
        array $allowedUpdates,
        array $parameters,
        string $stage,
        string $pollerName,
        int &$connectionRetries,
        int $waitSeconds = 0,
        bool $experimentalWidgets = false,
    ): array {
        try {
            $response = $this->requestWithRetry(
                $token,
                $allowedUpdates,
                $parameters,
                $waitSeconds,
                $pollerName,
                $stage,
                $connectionRetries,
                $experimentalWidgets,
            );
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
            throw new TelegramPollingFailure(
                $stage,
                previous: $error,
                connectionRetries: $connectionRetries,
                connectionErrorCode: $error instanceof ConnectionException ? $this->safeCurlErrorCode($error) : null,
            );
        }
    }

    /**
     * Retry only transport exceptions. Repeating the same getUpdates payload
     * preserves Telegram's offset semantics and never processes an update early.
     *
     * @param  list<string>  $allowedUpdates
     * @param  array<string, int>  $parameters
     */
    private function requestWithRetry(
        string $token,
        array $allowedUpdates,
        array $parameters,
        int $waitSeconds,
        string $pollerName,
        string $stage,
        int &$connectionRetries,
        bool $experimentalWidgets = false,
    ): Response {
        $requestRetries = 0;

        while (true) {
            try {
                $endpoint = $experimentalWidgets
                    ? 'https://api.telegram.org/beta/bot'.$token.'/test/getUpdates'
                    : 'https://api.telegram.org/bot'.$token.'/getUpdates';

                return Http::withOptions([
                    'force_ip_resolve' => config('services.telegram.ip_resolve', 'v6'),
                ])->timeout(max(10, $waitSeconds + 5))
                    ->connectTimeout(3)
                    ->post($endpoint, [
                        ...$parameters,
                        'timeout' => $waitSeconds,
                        'allowed_updates' => $allowedUpdates,
                    ]);
            } catch (ConnectionException $error) {
                $willRetry = $requestRetries < self::MAX_CONNECTION_RETRIES;
                Log::warning('Telegram polling connection exception', [
                    'poller' => $pollerName,
                    'stage' => $stage,
                    'exception' => class_basename($error),
                    'connection_error_code' => $this->safeCurlErrorCode($error),
                    'retry_attempt' => $willRetry ? $requestRetries + 1 : null,
                ]);

                if (! $willRetry) {
                    throw $error;
                }

                $requestRetries++;
                $connectionRetries++;
                usleep(self::CONNECTION_RETRY_BACKOFF_MICROSECONDS);
            }
        }
    }

    private function safeCurlErrorCode(ConnectionException $error): ?int
    {
        return preg_match('/cURL error\\s+(\\d+):/i', $error->getMessage(), $matches) === 1
            ? (int) $matches[1]
            : null;
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
