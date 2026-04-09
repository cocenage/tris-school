<?php

namespace App\Filament\Resources\DayOffRequests\Pages;

use App\Filament\Resources\DayOffRequests\DayOffRequestResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewDayOffRequest extends ViewRecord
{
    protected static string $resource = DayOffRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
