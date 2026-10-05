<?php

namespace App\Console\Commands;

use App\Services\Telegram\TelegramPollingFailure;
use App\Services\Telegram\TelegramScheduledInboundProcessor;
use App\Services\Telegram\TelegramUpdatePoller;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class TelegramScheduledPollCommand extends Command
{
    protected $signature = 'telegram:scheduled-poll {--limit=50 : Maximum updates fetched by the first poll (1-100)}';

    protected $description = 'Poll a bounded batch of inbound updates for the dedicated Scheduled Telegram bot';

    public function handle(TelegramScheduledInboundProcessor $processor, TelegramUpdatePoller $poller): int
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
            $result = $poller->poll(
                $token,
                ['message', 'edited_message'],
                $limit,
                fn (array $update) => $processor->process($update),
            );
        } catch (TelegramPollingFailure $error) {
            Log::warning('Scheduled Telegram polling failed', [
                'stage' => $error->stage,
                'update_id' => $error->updateId,
                'exception' => class_basename($error->getPrevious() ?? $error),
            ]);

            if ($error->stage === 'process' && $error->updateId !== null) {
                $this->error('Scheduled Telegram update '.$error->updateId.' failed; it and later updates remain unconfirmed.');
            } else {
                $this->error('Scheduled Telegram polling failed during '.$error->stage.'; pending updates remain available for retry.');
            }

            return self::FAILURE;
        }

        $this->info('Scheduled Telegram polling completed: '.$result['processed'].' update(s) processed.');

        return self::SUCCESS;
    }
}
