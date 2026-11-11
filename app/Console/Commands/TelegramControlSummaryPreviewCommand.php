<?php

namespace App\Console\Commands;

use App\Services\Telegram\TelegramScheduledControlSummaryBuilder;
use App\Services\Telegram\TelegramScheduledControlSummaryDemoFixture;
use App\Services\Telegram\TelegramScheduledControlSummaryRichFormatter;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use InvalidArgumentException;

class TelegramControlSummaryPreviewCommand extends Command
{
    protected $signature = 'telegram:control-summary-preview {--date=} {--json} {--fallback} {--demo}';

    protected $description = 'Read-only preview of scheduled control replies';

    public function handle(
        TelegramScheduledControlSummaryBuilder $builder,
        TelegramScheduledControlSummaryRichFormatter $formatter,
        TelegramScheduledControlSummaryDemoFixture $demo,
    ): int
    {
        try {
            $date = $this->option('date') ?: CarbonImmutable::now('Europe/Rome')->toDateString();
            $builder->day($date);
            $summary = $this->option('demo') ? $demo->build($date) : $builder->build($date);
            $this->line($this->option('json')
                ? json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
                : ($this->option('fallback') ? $formatter->fallbackHtml($summary) : $formatter->richMessage($summary)['markdown']));

            return self::SUCCESS;
        } catch (InvalidArgumentException $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }
    }
}
