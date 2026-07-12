<?php

namespace App\Filament\Resources\MobilityAlerts\Pages;

use App\Filament\Resources\MobilityAlerts\MobilityAlertResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListMobilityAlerts extends ListRecords
{
    protected static string $resource = MobilityAlertResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
