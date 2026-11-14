<?php

namespace App\Filament\Resources\ShiftDuties\Pages;

use App\Filament\Resources\ShiftDuties\ShiftDutyResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageShiftDuties extends ManageRecords
{
    protected static string $resource = ShiftDutyResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
