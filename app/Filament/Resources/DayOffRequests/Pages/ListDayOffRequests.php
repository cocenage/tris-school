<?php

namespace App\Filament\Resources\DayOffRequests\Pages;

use App\Filament\Resources\DayOffRequests\DayOffRequestResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListDayOffRequests extends ListRecords
{
    protected static string $resource = DayOffRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
