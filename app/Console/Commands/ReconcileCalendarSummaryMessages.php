<?php

namespace App\Console\Commands;

use App\Services\Calendar\CalendarSummaryRefreshService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class ReconcileCalendarSummaryMessages extends Command
{
    protected $signature = 'calendar:summaries-reconcile';

    protected $description = 'Refresh previously sent calendar summaries when their content has changed';

    public function handle(CalendarSummaryRefreshService $refreshService): int
    {
        $today = CarbonImmutable::now('Europe/Rome')->startOfDay();

        foreach ([$today, $today->addDay()] as $date) {
            $refreshService->refresh($date);
        }

        return self::SUCCESS;
    }
}
