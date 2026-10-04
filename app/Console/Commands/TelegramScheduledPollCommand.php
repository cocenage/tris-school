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
    protected $signature = 'telegram:scheduled-poll {--limit=50 : Maximum updates fetched per run (1-100)}';

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

        usort($updates, fn (array $left, array $right): int => ((int) ($left['update_id'] ?? -1)) <=> ((int) ($right['update_id'] ?? -1)));

        $lastSuccessfulUpdateId = null;
        $processed = 0;

        foreach ($updates as $update) {
            $updateId = $update['update_id'] ?? null;

            if (! is_numeric($updateId)) {
                return $this->stopAfterFailure($token, $lastSuccessfulUpdateId, 'Scheduled Telegram update has no valid update_id.');
            }

            try {
                $processor->process($update);
            } catch (Throwable $error) {
                Log::warning('Scheduled Telegram polling update failed', [
                    'update_id' => (int) $updateId,
                    'exception' => class_basename($error),
                ]);

                return $this->stopAfterFailure(
                    $token,
                    $lastSuccessfulUpdateId,
                    'Scheduled Telegram update '.$updateId.' failed; it and later updates remain unconfirmed.',
                );
            }

            $lastSuccessfulUpdateId = (int) $updateId;
            $processed++;
        }

        if ($lastSuccessfulUpdateId !== null && ! $this->confirmUpdates($token, $lastSuccessfulUpdateId)) {
            return self::FAILURE;
        }

        $this->info('Scheduled Telegram polling completed: '.$processed.' update(s) processed.');

        return self::SUCCESS;
    }

    private function stopAfterFailure(string $token, ?int $lastSuccessfulUpdateId, string $message): int
    {
        if ($lastSuccessfulUpdateId !== null) {
            $this->confirmUpdates($token, $lastSuccessfulUpdateId);
        }

        $this->error($message);

        return self::FAILURE;
    }

    private function confirmUpdates(string $token, int $lastSuccessfulUpdateId): bool
    {
        try {
            $this->getUpdates($token, [
                'offset' => $lastSuccessfulUpdateId + 1,
                'limit' => 1,
            ]);

            return true;
        } catch (Throwable $error) {
            $this->reportFailure('confirm', $error);

            return false;
        }
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
        $this->error('Scheduled Telegram polling failed during '.$stage.'; updates remain unconfirmed.');
    }
}
