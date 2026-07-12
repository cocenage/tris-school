<?php

namespace App\Filament\Resources\MobilityAlerts\Pages;

use App\Filament\Resources\MobilityAlerts\MobilityAlertResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditMobilityAlert extends EditRecord
{
    protected static string $resource = MobilityAlertResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
