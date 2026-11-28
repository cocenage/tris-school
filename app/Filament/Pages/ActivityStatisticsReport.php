<?php

namespace App\Filament\Pages;

use App\Filament\Resources\Activities\ActivityResource;
use App\Filament\Widgets\ActivityOverview;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Pages\Page;

class ActivityStatisticsReport extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-chart-bar';
    protected static ?string $navigationLabel = 'Статистика активности';
    protected static ?string $title = 'Статистика активности';
    protected string $view = 'filament.pages.dashboard-report';

    public static function getNavigationGroup(): ?string
    {
        return 'Аналитика';
    }

    public static function canAccess(): bool
    {
        return Filament::getCurrentPanel()?->getId() === 'admin' && auth()->user()?->isApproved() && ActivityResource::canViewAny();
    }

    protected function getHeaderWidgets(): array
    {
        return [ActivityOverview::class];
    }
}
