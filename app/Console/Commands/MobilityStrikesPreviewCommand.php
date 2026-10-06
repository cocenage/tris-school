<?php

namespace App\Console\Commands;

use App\Services\Mobility\MobilityStrikesSummaryBuilder;
use App\Services\Mobility\MobilityStrikesSummaryFormatter;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class MobilityStrikesPreviewCommand extends Command
{
    protected $signature = 'mobility:strikes-preview {--date= : Day in YYYY-MM-DD format}';

    protected $description = 'Preview the read-only daily strikes summary from persisted Mobility alerts';

    public function handle(MobilityStrikesSummaryBuilder $builder, MobilityStrikesSummaryFormatter $formatter): int
    {
        $date = (string) ($this->option('date') ?: now('Europe/Rome')->toDateString());
        try {
            $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $date, 'Europe/Rome');
        } catch (\Throwable) {
            $parsed = null;
        }

        if (! $parsed || $parsed->toDateString() !== $date) {
            $this->error('Use --date=YYYY-MM-DD.');

            return self::FAILURE;
        }

        $this->line($formatter->format($builder->build($date)));

        return self::SUCCESS;
    }
}
