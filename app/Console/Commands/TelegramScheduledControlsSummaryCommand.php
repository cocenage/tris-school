<?php

namespace App\Console\Commands;

use App\Services\Telegram\TelegramScheduledControlSummaryBuilder;
use App\Services\Telegram\TelegramScheduledControlSummaryFormatter;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class TelegramScheduledControlsSummaryCommand extends Command
{
    protected $signature = 'telegram:scheduled-controls-summary {--date=} {--through-date=} {--scheduled-message=} {--control-type=} {--chat=} {--json}';

    protected $description = 'Read-only scheduled control response statistics';

    public function handle(TelegramScheduledControlSummaryBuilder $builder, TelegramScheduledControlSummaryFormatter $formatter): int
    {
        try {
            $id = $this->option('scheduled-message');
            if ($id !== null && (! ctype_digit((string) $id) || (int) $id < 1)) {
                throw new \InvalidArgumentException('Invalid scheduled message ID.');
            }
            $summary = $builder->build($this->option('date') ?: CarbonImmutable::now('Europe/Rome')->toDateString(),
                $id ? (int) $id : null, $this->option('control-type'), $this->option('chat'), $this->option('through-date'));
            $this->line($this->option('json') ? json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) : $formatter->format($summary));

            return self::SUCCESS;
        } catch (\InvalidArgumentException $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }
    }
}
