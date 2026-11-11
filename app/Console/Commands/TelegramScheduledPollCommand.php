<?php

namespace App\Console\Commands;

use App\Services\Telegram\TelegramPollingFailure;
use App\Services\Telegram\TelegramPollingHealthMonitor;
use App\Services\Telegram\TelegramScheduledInboundProcessor;
use App\Services\Telegram\TelegramUpdatePoller;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class TelegramScheduledPollCommand extends Command
{
    protected $signature = 'telegram:scheduled-poll {--limit=50 : Maximum updates fetched by the first poll (1-100)}';

    protected $description = 'Poll a bounded batch of inbound updates for the dedicated Scheduled Telegram bot';

    public function handle(
        TelegramScheduledInboundProcessor $processor,
        TelegramUpdatePoller $poller,
        TelegramPollingHealthMonitor $health,
    ): int {
        $startedAt = hrtime(true);
        $token = (string) config('services.telegram.scheduled_bot_token');
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 100],
        ]);

        if ($token === '') {
            $health->recordFailure('scheduled', 'configuration', 'MissingBotToken', $this->elapsedMs($startedAt));
            $this->error('Scheduled Telegram bot token is not configured.');

            return self::FAILURE;
        }

        if ($limit === false) {
            $health->recordFailure('scheduled', 'configuration', 'InvalidPollingOption', $this->elapsedMs($startedAt));
            $this->error('The --limit option must be an integer from 1 to 100.');

            return self::FAILURE;
        }

        try {
            $result = $poller->poll(
                $token,
                ['message', 'edited_message', 'callback_query'],
                $limit,
                fn (array $update) => $processor->process($update),
                pollerName: 'scheduled',
            );
        } catch (TelegramPollingFailure $error) {
            $durationMs = $this->elapsedMs($startedAt);
            $connectionRetries = $error->connectionRetries;
            $health->recordFailure(
                'scheduled',
                $error->stage,
                class_basename($error->getPrevious() ?? $error),
                $durationMs,
                $connectionRetries,
                $error->connectionErrorCode,
            );
            Log::warning('Scheduled Telegram polling failed', [
                'stage' => $error->stage,
                'update_id' => $error->updateId,
                'exception' => class_basename($error->getPrevious() ?? $error),
                'duration_ms' => $durationMs,
                'connection_retries' => $connectionRetries,
                'connection_error_code' => $error->connectionErrorCode,
            ]);

            if ($error->stage === 'process' && $error->updateId !== null) {
                $this->error('Scheduled Telegram update '.$error->updateId.' failed; it and later updates remain unconfirmed.');
            } else {
                $this->error('Scheduled Telegram polling failed during '.$error->stage.'; pending updates remain available for retry.');
            }

            return self::FAILURE;
        }

        $durationMs = $this->elapsedMs($startedAt);
        $health->recordSuccess('scheduled', $durationMs, $result['processed'], $result['network_retries']);
        Log::info('Scheduled Telegram polling completed', [
            'duration_ms' => $durationMs,
            'updates_processed' => $result['processed'],
            'connection_retries' => $result['network_retries'],
        ]);
        $this->info('Scheduled Telegram polling completed: '.$result['processed'].' update(s) processed.');

        return self::SUCCESS;
    }

    private function elapsedMs(int $startedAt): int
    {
        return max(0, intdiv(hrtime(true) - $startedAt, 1_000_000));
    }
}
