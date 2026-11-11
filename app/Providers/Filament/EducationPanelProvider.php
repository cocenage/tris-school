<?php

namespace App\Providers\Filament;

use App\Filament\Pages\EmployeeWorkCalendarReport;
use App\Filament\Resources\Apartments\ApartmentResource;
use App\Filament\Resources\CalendarEvents\CalendarEventResource;
use App\Filament\Resources\ControlResponses\ControlResponseResource;
use App\Filament\Resources\Controls\ControlResource;
use App\Filament\Resources\DayOffRequests\DayOffRequestResource;
use App\Filament\Resources\SupervisorEmployees\SupervisorEmployeeResource;
use App\Filament\Resources\Users\UserResource;
use App\Filament\Resources\VacationRequests\VacationRequestResource;
use App\Http\Middleware\EndRolePreviewOnFilamentAccess;
use App\Services\Authorization\SupervisorPanelAccess;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationItem;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
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
            ->path('admin/supervisor')
            ->login()
            ->brandName('Панель супервайзера')
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

            ->pages([
                Dashboard::class,
                EmployeeWorkCalendarReport::class,
            ])
            ->navigationGroups([
                'Сотрудники и графики',
                'Заявки',
                'Контроль качества',
                'Рабочие инструменты',
            ])
            ->navigationItems([
                NavigationItem::make('Задачи')
                    ->icon('heroicon-o-clipboard-document-list')
                    ->group('Рабочие инструменты')
                    ->url(fn (): string => route('page-tasks'))
                    ->visible(fn (): bool => app(SupervisorPanelAccess::class)->canUseSupervisorPanel(auth()->user())),
                NavigationItem::make('Инструкции')
                    ->icon('heroicon-o-book-open')
                    ->group('Рабочие инструменты')
                    ->url(fn (): string => route('page-home.instructions'))
                    ->visible(fn (): bool => app(SupervisorPanelAccess::class)->canUseSupervisorPanel(auth()->user())),
            ])
            ->resources([
                UserResource::class,
                SupervisorEmployeeResource::class,
                DayOffRequestResource::class,
                VacationRequestResource::class,
                ControlResource::class,
                ControlResponseResource::class,
                ApartmentResource::class,
                CalendarEventResource::class,
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
