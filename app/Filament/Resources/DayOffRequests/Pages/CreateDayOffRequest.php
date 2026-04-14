<?php

namespace App\Filament\Resources\DayOffRequests\Pages;

use App\Filament\Resources\DayOffRequests\DayOffRequestResource;
use Filament\Resources\Pages\CreateRecord;

class CreateDayOffRequest extends CreateRecord
{
    protected static string $resource = DayOffRequestResource::class;

    protected function afterCreate(): void
    {
        $this->record->syncStatusAndNotify();
    }
}
