<?php

namespace App\Console\Commands;

use App\Services\Telegram\TelegramScheduledInboundProcessor;
use Illuminate\Console\Command;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class TelegramScheduledPollCommand extends Command
{
    protected $signature = 'telegram:scheduled-poll {--limit=50 : Maximum updates fetched by the first poll (1-100)}';

    protected $description = 'Poll a bounded batch of inbound updates for the dedicated Scheduled Telegram bot';

    public function handle(TelegramScheduledInboundProcessor $processor): int
    {
        $token = (string) config('services.telegram.scheduled_bot_token');
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 100],
        ]);

        if ($token === '') {
            $this->error('Scheduled Telegram bot token is not configured.');

            return self::FAILURE;
        }

        if ($limit === false) {
            $this->error('The --limit option must be an integer from 1 to 100.');

            return self::FAILURE;
        }

        try {
            $updates = $this->getUpdates($token, ['limit' => $limit]);
        } catch (Throwable $error) {
            $this->reportFailure('fetch', $error);

            return self::FAILURE;
        }

        $processed = $this->processUpdates($updates, $processor);

        if (! $processed['ok']) {
            return self::FAILURE;
        }

        // The offset acknowledges the previous batch and this same request may
        // return the next update. Process it instead of treating getUpdates as
        // a confirmation-only endpoint.
        if ($processed['last_update_id'] !== null) {
            try {
                $nextUpdates = $this->getUpdates($token, [
                    'offset' => $processed['last_update_id'] + 1,
                    'limit' => 1,
                ]);
            } catch (Throwable $error) {
                $this->reportFailure('acknowledge', $error);

                return self::FAILURE;
            }

            $next = $this->processUpdates($nextUpdates, $processor);

            if (! $next['ok']) {
                return self::FAILURE;
            }

            $processed['count'] += $next['count'];
        }

        $this->info('Scheduled Telegram polling completed: '.$processed['count'].' update(s) processed.');

        return self::SUCCESS;
    }

    /**
     * @param  list<array<string, mixed>>  $updates
     * @return array{ok: bool, count: int, last_update_id: int|null}
     */
    private function processUpdates(array $updates, TelegramScheduledInboundProcessor $processor): array
    {
        usort($updates, fn (array $left, array $right): int => ((int) ($left['update_id'] ?? -1)) <=> ((int) ($right['update_id'] ?? -1)));

        $lastSuccessfulUpdateId = null;
        $processed = 0;

        foreach ($updates as $update) {
            $updateId = $update['update_id'] ?? null;

            if (! is_numeric($updateId)) {
                $this->error('Scheduled Telegram update has no valid update_id; this batch remains unconfirmed.');

                return ['ok' => false, 'count' => $processed, 'last_update_id' => null];
            }

            try {
                $processor->process($update);
            } catch (Throwable $error) {
                Log::warning('Scheduled Telegram polling update failed', [
                    'update_id' => (int) $updateId,
                    'exception' => class_basename($error),
                ]);
                $this->error('Scheduled Telegram update '.$updateId.' failed; it and later updates remain unconfirmed.');

                return ['ok' => false, 'count' => $processed, 'last_update_id' => null];
            }

            $lastSuccessfulUpdateId = (int) $updateId;
            $processed++;
        }

        return ['ok' => true, 'count' => $processed, 'last_update_id' => $lastSuccessfulUpdateId];
    }

    /** @return list<array<string, mixed>> */
    private function getUpdates(string $token, array $parameters): array
    {
        $response = Http::timeout(10)
            ->connectTimeout(3)
            ->post('https://api.telegram.org/bot'.$token.'/getUpdates', [
                ...$parameters,
                'timeout' => 0,
                'allowed_updates' => ['message', 'edited_message'],
            ]);

        $this->assertSuccessfulResponse($response);
        $updates = data_get($response->json(), 'result');

        if (! is_array($updates)) {
            throw new \RuntimeException('telegram_invalid_result');
        }

        foreach ($updates as $update) {
            if (! is_array($update)) {
                throw new \RuntimeException('telegram_invalid_update');
            }
        }

        return array_values($updates);
    }

    private function assertSuccessfulResponse(Response $response): void
    {
        if (! $response->successful() || data_get($response->json(), 'ok') !== true) {
            throw new \RuntimeException('telegram_http_failure', $response->status());
        }
    }

    private function reportFailure(string $stage, Throwable $error): void
    {
        Log::warning('Scheduled Telegram polling failed', [
            'stage' => $stage,
            'exception' => class_basename($error),
            'reason' => str_starts_with($error->getMessage(), 'telegram_') ? $error->getMessage() : null,
            'http_status' => $error->getCode() > 0 ? $error->getCode() : null,
        ]);
        $this->error('Scheduled Telegram polling failed during '.$stage.'; pending updates remain available for retry.');
    }
}
