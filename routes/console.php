<?php

use App\Services\Emergency\EmergencyScheduleRegistrar;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('calendar:notify-tomorrow')
    ->dailyAt('6:00');

Schedule::command('tasks:check-deadlines')->everyFifteenMinutes();

Schedule::command('mobility:sync')
    ->everyFifteenMinutes()
    ->withoutOverlapping();

Schedule::command('mobility:digest')
    ->dailyAt('08:00')
    ->withoutOverlapping();

Schedule::command('telegram:operational-replay --through-now')
    ->dailyAt('20:25')
    ->timezone('Europe/Rome')
    ->withoutOverlapping();

Schedule::command('telegram:evening-intelligence-send')
    ->dailyAt('20:30')
    ->timezone('Europe/Rome')
    ->withoutOverlapping();

Schedule::command('tris-mare:sync')
    ->dailyAt('20:15')
    ->timezone('Europe/Rome');

Schedule::command('telegram:scheduled-messages-send')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command('queue:work database --queue=default --stop-when-empty --tries=8 --timeout=30 --max-time=50')
    ->everyMinute()
    ->withoutOverlapping();

app(EmergencyScheduleRegistrar::class)->register(app(\Illuminate\Console\Scheduling\Schedule::class));
