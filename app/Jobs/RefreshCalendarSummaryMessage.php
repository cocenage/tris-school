<?php

namespace App\Jobs;

use App\Services\Calendar\CalendarSummaryRefreshService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RefreshCalendarSummaryMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public string $date)
    {
        $this->afterCommit = true;
    }

    public function handle(CalendarSummaryRefreshService $refreshService): void
    {
        $refreshService->refresh($this->date);
    }
}
