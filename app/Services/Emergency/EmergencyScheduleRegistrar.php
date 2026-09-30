<?php

namespace App\Services\Emergency;

use Illuminate\Console\Scheduling\Schedule;

class EmergencyScheduleRegistrar
{
    public function __construct(private readonly EmergencyPublicationTarget $target) {}

    public function register(Schedule $schedule): void
    {
        if (! $this->target->isConfigured()) {
            return;
        }

        $schedule->command('emergency:publish')
            ->everyFiveMinutes()
            ->withoutOverlapping();
    }
}
