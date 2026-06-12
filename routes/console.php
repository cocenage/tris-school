<?php

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
    ->dailyAt('07:30')
    ->withoutOverlapping();

Schedule::command('mobility:digest')
    ->dailyAt('08:00')
    ->withoutOverlapping();

    Schedule::command('tris-mare:sync')
    ->dailyAt('20:15')
    ->timezone('Europe/Rome');