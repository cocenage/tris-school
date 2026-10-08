<?php

namespace App\Console\Commands;

use App\Services\Telegram\TelegramTomorrowSummaryBuilder;
use App\Services\Telegram\TelegramTomorrowSummaryFormatter;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

class TelegramTomorrowSummaryPreviewCommand extends Command
{
    protected $signature = 'telegram:tomorrow-summary-preview {--date= : Target date in Europe/Rome, YYYY-MM-DD} {--json : Print structured preview}';

    protected $description = 'Preview known facts for tomorrow without Telegram delivery';

    public function handle(TelegramTomorrowSummaryBuilder $builder, TelegramTomorrowSummaryFormatter $formatter): int
    {
        $timezone = 'Europe/Rome';
        $value = $this->option('date');
        $date = CarbonImmutable::now($timezone)->addDay()->startOfDay();

        if ($value !== null) {
            try {
                $date = CarbonImmutable::createFromFormat('!Y-m-d', (string) $value, $timezone);
            } catch (Throwable) {
                $date = false;
            }

            if (! $date || $date->format('Y-m-d') !== $value) {
                $this->error('Date must be a valid YYYY-MM-DD date.');

                return self::FAILURE;
            }
        }

        $summary = $builder->build($date);

        if ($this->option('json')) {
            $this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } else {
            $this->line($formatter->format($summary));
        }

        return self::SUCCESS;
    }
}
