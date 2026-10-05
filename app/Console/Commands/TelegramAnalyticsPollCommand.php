<?php

namespace App\Console\Commands;

use App\Http\Controllers\TelegramAnalyticsWebhookController;
use App\Services\Telegram\TelegramPollingFailure;
use App\Services\Telegram\TelegramScheduledControlResponseService;
use App\Services\Telegram\TelegramUpdatePoller;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class TelegramAnalyticsPollCommand extends Command
{
    protected $signature = 'telegram:analytics-poll {--limit=50 : Maximum updates fetched by the first poll (1-100)}';

    protected $description = 'Poll a bounded batch of inbound updates for the Analytics Telegram bot';

    public function handle(
        TelegramUpdatePoller $poller,
        TelegramAnalyticsWebhookController $processor,
        TelegramScheduledControlResponseService $responses,
    ): int {
        $token = (string) config('services.telegram.analytics_bot_token');
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 100],
        ]);

        if ($token === '') {
            $this->error('Analytics Telegram bot token is not configured.');

            return self::FAILURE;
        }

        if ($limit === false) {
            $this->error('The --limit option must be an integer from 1 to 100.');

            return self::FAILURE;
        }

        try {
            $result = $poller->poll(
                $token,
                ['message', 'edited_message', 'channel_post', 'edited_channel_post'],
                $limit,
                function (array $update) use ($processor, $responses): void {
                    $response = $processor->processUpdate($update, $responses);

                    if (! $response->isSuccessful() || data_get($response->getData(true), 'ok') !== true) {
                        throw new RuntimeException('analytics_update_processing_failed');
                    }
                },
            );
        } catch (TelegramPollingFailure $error) {
            $this->reportFailure($error);

            return self::FAILURE;
        }

        $this->info('Analytics Telegram polling completed: '.$result['processed'].' update(s) processed.');

        return self::SUCCESS;
    }

    private function reportFailure(TelegramPollingFailure $error): void
    {
        Log::warning('Analytics Telegram polling failed', [
            'stage' => $error->stage,
            'update_id' => $error->updateId,
            'exception' => class_basename($error->getPrevious() ?? $error),
        ]);

        if ($error->stage === 'process' && $error->updateId !== null) {
            $this->error('Analytics Telegram update '.$error->updateId.' failed; it and later updates remain unconfirmed.');

            return;
        }

        $this->error('Analytics Telegram polling failed during '.$error->stage.'; pending updates remain available for retry.');
    }
}
