<?php

namespace App\Console\Commands;

use App\Http\Controllers\TelegramWorkWebhookController;
use App\Services\Telegram\TelegramAssistantService;
use App\Services\Telegram\TelegramDistrictRouteRegistry;
use App\Services\Telegram\TelegramPollingFailure;
use App\Services\Telegram\TelegramScheduledControlResponseService;
use App\Services\Telegram\TelegramUpdateIngestService;
use App\Services\Telegram\TelegramUpdatePoller;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class TelegramWorkPollCommand extends Command
{
    protected $signature = 'telegram:work-poll {--limit=50 : Maximum updates fetched by each poll (1-100)} {--listen-seconds=0 : Bounded long-poll duration (0-50)}';

    protected $description = 'Poll a bounded batch of inbound updates for the Work Telegram bot';

    public function handle(
        TelegramUpdatePoller $poller,
        TelegramWorkWebhookController $processor,
        TelegramUpdateIngestService $ingestService,
        TelegramAssistantService $assistantService,
        TelegramScheduledControlResponseService $responses,
        TelegramDistrictRouteRegistry $districts,
    ): int {
        $token = (string) config('services.telegram.bot_token');
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 100],
        ]);
        $listenSeconds = filter_var($this->option('listen-seconds'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 0, 'max_range' => 50],
        ]);

        if ($token === '') {
            $this->error('Work Telegram bot token is not configured.');

            return self::FAILURE;
        }

        if ($limit === false) {
            $this->error('The --limit option must be an integer from 1 to 100.');

            return self::FAILURE;
        }

        if ($listenSeconds === false) {
            $this->error('The --listen-seconds option must be an integer from 0 to 50.');

            return self::FAILURE;
        }

        try {
            $lock = Cache::lock('telegram-work-poll', 3600);

            if (! $lock->get()) {
                $this->error('Another Work Telegram poll is still running.');

                return self::FAILURE;
            }
        } catch (Throwable $error) {
            Log::warning('Work Telegram polling lock failed', ['exception' => class_basename($error)]);
            $this->error('Work Telegram polling could not acquire its lock.');

            return self::FAILURE;
        }

        try {
            $deadline = microtime(true) + $listenSeconds;
            $offset = null;
            $total = 0;
            $attempts = 0;

            do {
                $waitSeconds = $listenSeconds > 0
                    ? min(15, max(1, (int) ceil($deadline - microtime(true))))
                    : 0;
                $result = $poller->poll(
                    $token,
                    ['message', 'edited_message', 'channel_post', 'callback_query'],
                    $limit,
                    function (array $update) use ($processor, $ingestService, $assistantService, $responses, $districts): void {
                        $response = $processor->processUpdate(
                            $update,
                            $ingestService,
                            $assistantService,
                            $responses,
                            $districts,
                            false,
                        );

                        if (! $response->isSuccessful()
                            || data_get($response->getData(true), 'ok') !== true
                            || data_get($response->getData(true), 'handled') === false) {
                            throw new RuntimeException('work_update_processing_failed');
                        }
                    },
                    $offset,
                    $waitSeconds,
                );
                $attempts++;
                $total += $result['processed'];
                if (isset($result['last_update_id'])) {
                    $offset = $result['last_update_id'] + 1;
                }
                if ($listenSeconds > 0 && $result['processed'] === 0 && microtime(true) < $deadline) {
                    usleep(250_000);
                }
            } while ($listenSeconds > 0 && microtime(true) < $deadline && $attempts < 100);
        } catch (TelegramPollingFailure $error) {
            $this->reportFailure($error);

            return self::FAILURE;
        } finally {
            $lock->release();
        }

        $this->info('Work Telegram polling completed: '.$total.' update(s) processed.');

        return self::SUCCESS;
    }

    private function reportFailure(TelegramPollingFailure $error): void
    {
        Log::warning('Work Telegram polling failed', [
            'stage' => $error->stage,
            'update_id' => $error->updateId,
            'exception' => class_basename($error->getPrevious() ?? $error),
        ]);

        if ($error->stage === 'process' && $error->updateId !== null) {
            $this->error('Work Telegram update '.$error->updateId.' failed; it and later updates remain unconfirmed.');

            return;
        }

        if ($error->stage === 'acknowledge') {
            $this->error('Work Telegram acknowledgement status is uncertain; completed updates are stored and retries are idempotent.');

            return;
        }

        $this->error('Work Telegram polling failed during '.$error->stage.'; pending updates remain available for retry.');
    }
}
