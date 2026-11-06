<?php

namespace App\Providers;

use App\Http\Middleware\BlockRolePreviewWrites;
use App\Models\Apartment;
use App\Models\CalendarEvent;
use App\Models\Control;
use App\Models\ControlResponse;
use App\Models\DayOffRequest;
use App\Models\DayOffRequestDay;
use App\Models\User;
use App\Models\VacationRequest;
use App\Models\VacationRequestDay;
use App\Observers\UserObserver;
use App\Policies\ApartmentPolicy;
use App\Policies\QualityControlPolicy;
use App\Policies\SupervisorCalendarEventPolicy;
use App\Policies\SupervisorRequestPolicy;
use App\Services\Telegram\TelegramOutboundMonitor;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationItem;
use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(TelegramOutboundMonitor::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Livewire::setUpdateRoute(function ($handle, $path) {
            return Route::post($path, $handle)
                ->middleware(BlockRolePreviewWrites::class)
                ->name('role-preview.livewire.update');
        });

        Event::listen(RequestSending::class, [TelegramOutboundMonitor::class, 'sending']);
        Event::listen(ResponseReceived::class, [TelegramOutboundMonitor::class, 'received']);
        Event::listen(ConnectionFailed::class, [TelegramOutboundMonitor::class, 'failed']);
        Gate::policy(Apartment::class, ApartmentPolicy::class);
        Gate::policy(CalendarEvent::class, SupervisorCalendarEventPolicy::class);
        Gate::policy(Control::class, QualityControlPolicy::class);
        Gate::policy(ControlResponse::class, QualityControlPolicy::class);
        Gate::policy(DayOffRequest::class, SupervisorRequestPolicy::class);
        Gate::policy(DayOffRequestDay::class, SupervisorRequestPolicy::class);
        Gate::policy(VacationRequest::class, SupervisorRequestPolicy::class);
        Gate::policy(VacationRequestDay::class, SupervisorRequestPolicy::class);

        Filament::serving(function () {
            Filament::registerNavigationItems([
                NavigationItem::make('На сайт')
                    ->url('/')
                    ->icon('heroicon-m-arrow-left')
                    ->sort(100),
            ]);
        });
        User::observe(UserObserver::class);
    }
}
