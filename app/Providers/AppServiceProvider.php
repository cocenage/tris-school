<?php

namespace App\Providers;

use App\Models\User;
use App\Observers\UserObserver;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationItem;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
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
