<?php

namespace App\Filament\Resources\DayOffRequests\Pages;

use App\Filament\Resources\DayOffRequests\DayOffRequestResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListDayOffRequests extends ListRecords
{
    protected static string $resource = DayOffRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('backToSite')
                ->label('На сайт')
                ->icon('heroicon-m-arrow-left')
                ->url(url('/'))
                ->color('gray')
                ->outlined(),
        ];
    }
}
