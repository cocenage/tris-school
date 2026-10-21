<?php

namespace App\Providers;

use App\Models\Apartment;
use App\Models\User;
use App\Observers\UserObserver;
use App\Policies\ApartmentPolicy;
use App\Services\Telegram\TelegramOutboundMonitor;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationItem;
use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

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
        Event::listen(RequestSending::class, [TelegramOutboundMonitor::class, 'sending']);
        Event::listen(ResponseReceived::class, [TelegramOutboundMonitor::class, 'received']);
        Event::listen(ConnectionFailed::class, [TelegramOutboundMonitor::class, 'failed']);
        Gate::policy(Apartment::class, ApartmentPolicy::class);

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
