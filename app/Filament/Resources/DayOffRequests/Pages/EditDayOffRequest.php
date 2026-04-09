<?php

namespace App\Filament\Resources\DayOffRequests\Pages;

use App\Filament\Resources\DayOffRequests\DayOffRequestResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditDayOffRequest extends EditRecord
{
    protected static string $resource = DayOffRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}
