<?php

namespace App\Console\Commands;

use App\Services\Telegram\TelegramScheduledControlSummaryBuilder;
use App\Services\Telegram\TelegramScheduledControlSummaryFormatter;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use InvalidArgumentException;

class TelegramControlSummaryPreviewCommand extends Command
{
    protected $signature = 'telegram:control-summary-preview {--date=} {--json}';

    protected $description = 'Read-only preview of scheduled control replies';

    public function handle(TelegramScheduledControlSummaryBuilder $builder, TelegramScheduledControlSummaryFormatter $formatter): int
    {
        try {
            $date = $this->option('date') ?: CarbonImmutable::now('Europe/Rome')->toDateString();
            $summary = $builder->build($date);
            $this->line($this->option('json')
                ? json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
                : $formatter->format($summary));

            return self::SUCCESS;
        } catch (InvalidArgumentException $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }
    }
}
