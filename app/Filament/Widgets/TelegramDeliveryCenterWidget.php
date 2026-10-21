<?php

namespace App\Filament\Widgets;

use App\Services\Telegram\TelegramDeliveryCenter;
use Filament\Widgets\Widget;

class TelegramDeliveryCenterWidget extends Widget
{
    protected static ?int $sort = 2;

    protected string $view = 'filament.widgets.telegram-delivery-center-widget';

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return auth()->user()?->isAdmin() === true;
    }

    protected function getViewData(): array
    {
        abort_unless(static::canView(), 403);

        return ['delivery' => app(TelegramDeliveryCenter::class)->snapshot()];
    }
}
