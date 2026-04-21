<?php

namespace App\Filament\Resources\Controls\Pages;

use App\Filament\Resources\Controls\ControlResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListControls extends ListRecords
{
    protected static string $resource = ControlResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
