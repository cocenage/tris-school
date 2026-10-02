<?php

namespace App\Console\Commands;

use App\Services\Telegram\TelegramScheduledControlSummaryBuilder;
use App\Services\Telegram\TelegramScheduledControlSummaryDeliveryService;
use App\Services\Telegram\TelegramScheduledControlSummaryFormatter;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class TelegramScheduledControlsSummarySendCommand extends Command
{
    protected $signature = 'telegram:scheduled-controls-summary-send {--date=} {--dry-run} {--json} {--only-if-due}';

    protected $description = 'Reserve a daily scheduled control summary for analytics-bot queue delivery';

    public function handle(TelegramScheduledControlSummaryBuilder $builder, TelegramScheduledControlSummaryFormatter $formatter, TelegramScheduledControlSummaryDeliveryService $delivery): int
    {
        try {
            $date = $this->option('date') ?: CarbonImmutable::now('Europe/Rome')->toDateString();
            if ($this->option('dry-run')) {
                $summary = $builder->build($date);
                $this->line($this->option('json') ? json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) : $formatter->format($summary));

                return self::SUCCESS;
            }
            if ($this->option('only-if-due') && config('services.telegram.scheduled_summary_enabled', false)) {
                $delivery->recoverPending();
            }
            $result = $delivery->queue($date, (bool) $this->option('only-if-due'));
            $this->line(json_encode($result, JSON_THROW_ON_ERROR));

            return ($result['failed'] ?? 0) ? self::FAILURE : self::SUCCESS;
        } catch (\Throwable $error) {
            $this->error('Scheduled control summary could not be built or queued: '.class_basename($error));

            return self::FAILURE;
        }
    }
}
