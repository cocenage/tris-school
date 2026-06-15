<?php

namespace App\Providers\Filament;

use App\Filament\Resources\DayOffRequests\DayOffRequestResource;
use App\Filament\Resources\Users\UserResource;
use App\Filament\Resources\VacationRequests\VacationRequestResource;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Widgets\AccountWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class EducationPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('education')
            ->path('admin/education')
            ->login()
            ->brandName('Админ-панель обучения')
            ->colors([
                'primary' => Color::Amber,
                'gray' => Color::Zinc,
                'success' => Color::Emerald,
                'warning' => Color::Orange,
                'danger' => Color::Rose,
                'info' => Color::Sky,
            ])
            ->sidebarCollapsibleOnDesktop()
            ->globalSearchKeyBindings(['command+k', 'ctrl+k'])
            ->maxContentWidth('full')

            ->discoverResources(
                in: app_path('Filament/Education/Resources'),
                for: 'App\\Filament\\Education\\Resources'
            )
            ->discoverPages(
                in: app_path('Filament/Education/Pages'),
                for: 'App\\Filament\\Education\\Pages'
            )
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(
                in: app_path('Filament/Education/Widgets'),
                for: 'App\\Filament\\Education\\Widgets'
            )
            ->widgets([

            ])
            ->resources([
                UserResource::class,
                DayOffRequestResource::class,
                VacationRequestResource::class,

            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);

    }
}