<?php

use App\Services\Emergency\EmergencyScheduleRegistrar;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('calendar:notify-tomorrow')
    ->dailyAt('6:00')
    ->timezone('Europe/Rome');

Schedule::command('calendar:notify-celebrations')
    ->dailyAt('06:05')
    ->timezone('Europe/Rome')
    ->withoutOverlapping();

Schedule::command('tasks:check-deadlines')->everyFifteenMinutes();

Schedule::command('mobility:sync')
    ->everyFifteenMinutes()
    ->when(fn (): bool => config('mobility.strike_notifications_enabled') || now()->minute === 0)
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

Schedule::command('telegram:scheduled-messages-send')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command('telegram:scheduled-poll')
    ->everyFiveMinutes()
    ->withoutOverlapping();

if ((bool) config('services.telegram.analytics_polling_enabled', false)) {
    Schedule::command('telegram:analytics-poll')
        ->everyTwoMinutes()
        ->withoutOverlapping();
}

if ((bool) config('services.telegram.work_polling_enabled', false)) {
    Schedule::command('telegram:work-poll --listen-seconds=50')
        ->everyTwoMinutes()
        ->runInBackground()
        ->withoutOverlapping();
}

Schedule::command('telegram:scheduled-controls-summary-send --only-if-due')
    ->everyFifteenMinutes()
    ->timezone('Europe/Rome')
    ->withoutOverlapping();

// Recover committed salary decisions if the initial queue dispatch was lost.
Schedule::call(function (): void {
    if (! \Illuminate\Support\Facades\Schema::hasTable('salary_question_decisions')) {
        return;
    }
    \App\Models\SalaryQuestionDecision::query()
        ->where('updated_at', '<', now()->subMinute())
        ->where(function ($query): void {
            $query->where('delivery_status', 'pending')
                ->orWhere(fn ($q) => $q->whereIn('card_sync_status', ['pending', 'failed'])->where('card_sync_attempts', '<', 5));
        })->orderBy('id')->limit(50)->get(['id'])
        ->each(fn ($decision) => \App\Jobs\DeliverSalaryQuestionDecision::dispatch($decision->id));
})->name('salary-question-decision-recovery')->everyMinute()->withoutOverlapping();

Schedule::call(function (): void {
    foreach (['schedule' => \App\Models\ScheduleQuestionDecision::class, 'feedback' => \App\Models\FeedbackSuggestionDecision::class] as $kind => $model) {
        if (! \Illuminate\Support\Facades\Schema::hasTable((new $model)->getTable())) {
            continue;
        }
        $model::query()->where('updated_at', '<', now()->subMinute())
            ->where(function ($query): void {
                $query->where('delivery_status', 'pending')
                    ->orWhere(fn ($q) => $q->whereIn('card_sync_status', ['pending', 'failed'])->where('card_sync_attempts', '<', 5));
            })->orderBy('id')->limit(50)->get(['id'])
            ->each(fn ($decision) => \App\Jobs\DeliverSalaryQuestionDecision::dispatch($decision->id, $kind));
    }
})->name('staff-text-decision-recovery')->everyMinute()->withoutOverlapping();

Schedule::call(function (): void {
    if (! \Illuminate\Support\Facades\Schema::hasTable('structured_request_decisions')) {
        return;
    }
    \App\Models\StructuredRequestDecision::query()->where('updated_at', '<', now()->subMinute())
        ->where(function ($query): void {
            $query->where('delivery_status', 'pending')
                ->orWhere(fn ($q) => $q->whereIn('card_sync_status', ['pending', 'failed'])->where('card_sync_attempts', '<', 5));
        })->orderBy('id')->limit(50)->get(['id'])
        ->each(fn ($decision) => \App\Jobs\DeliverStructuredRequestDecision::dispatch($decision->id));
})->name('structured-request-decision-recovery')->everyMinute()->withoutOverlapping();

Schedule::command('controls:dispatch-completion-notifications')
    ->everyMinute()
    ->withoutOverlapping();

// Experimental uploads must not schedule DB queries on production while
// Focus V2 is disabled or before its isolated staging migrations exist.
Schedule::command('focus-v2:prune-media')
    ->dailyAt('03:25')
    ->timezone('Europe/Rome')
    ->when(fn (): bool => config('focus-v2.enabled', false) && ! app()->environment('production'))
    ->withoutOverlapping();

// Unlike the legacy preview-only media pruner, the new sandbox cleanup
// is safe in production because it selects *only* sandbox draft states and
// private paths under focus-v2-sandbox/.
Schedule::command('focus-v2:prune-sandbox')
    ->dailyAt('03:40')
    ->timezone('Europe/Rome')
    ->when(fn (): bool => config('focus-v2.enabled', false))
    ->withoutOverlapping();

app(EmergencyScheduleRegistrar::class)->register(app(Illuminate\Console\Scheduling\Schedule::class));
