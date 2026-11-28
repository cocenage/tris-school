<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\TelegramDeliveryCenterWidget;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Pages\Page;

class TelegramDeliveryReport extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-paper-airplane';
    protected static ?string $navigationLabel = 'Центр доставки';
    protected static ?string $title = 'Telegram · Центр доставки';
    protected static ?int $navigationSort = 1;
    protected string $view = 'filament.pages.dashboard-report';

    public static function getNavigationGroup(): ?string
    {
        return 'Коммуникации';
    }

    public static function canAccess(): bool
    {
        return Filament::getCurrentPanel()?->getId() === 'admin' && auth()->user()?->isApproved() && auth()->user()?->isAdmin();
    }

    protected function getHeaderWidgets(): array
    {
        return [TelegramDeliveryCenterWidget::class];
    }
}
