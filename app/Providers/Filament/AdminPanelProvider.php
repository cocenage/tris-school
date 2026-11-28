<?php

namespace App\Providers\Filament;

use App\Filament\Widgets\ActivityOverview;
use App\Filament\Widgets\AdminRequestsOverview;
use App\Filament\Widgets\LatestActivity;
use App\Filament\Widgets\TelegramDeliveryCenterWidget;
use App\Http\Middleware\EndRolePreviewOnFilamentAccess;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use App\Filament\Support\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use App\Filament\Support\SharedPanelStyle;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return SharedPanelStyle::configure($panel)
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()

            ->brandName('TRIS · Администрация')
            ->navigationGroups([
                'Команда',
                'Операционная работа',
                'Заявки',
                'Контроль качества',
                'Объекты и ресурсы',
                'База знаний',
                'Аналитика',
                'Коммуникации',
                'Система',
            ])
            ->navigationItems([
                \Filament\Navigation\NavigationItem::make('Устройства и уведомления')
                    ->group('Система')
                    ->icon('heroicon-o-device-phone-mobile')
                    ->url(fn () => route('mobile.push.admin'))
                    ->visible(fn () => \App\Services\MobilePush\PushService::enabled() && auth()->user()?->isApproved() && auth()->user()?->role === 'admin')
                    ->sort(110),
            ])

            ->sidebarCollapsibleOnDesktop()
            ->globalSearchKeyBindings(['command+k', 'ctrl+k'])
            ->maxContentWidth('full')
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                \App\Filament\Widgets\WorkspaceOverview::class,
                ActivityOverview::class,
                AdminRequestsOverview::class,
                LatestActivity::class,
                TelegramDeliveryCenterWidget::class,
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
                EndRolePreviewOnFilamentAccess::class,
            ]);
    }
}
