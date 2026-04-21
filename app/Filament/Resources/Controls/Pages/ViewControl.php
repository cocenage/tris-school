<?php

namespace App\Filament\Resources\Controls\Pages;

use App\Filament\Resources\Controls\ControlResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewControl extends ViewRecord
{
    protected static string $resource = ControlResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
