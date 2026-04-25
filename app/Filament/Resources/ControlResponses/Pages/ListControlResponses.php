<?php

namespace App\Filament\Resources\ControlResponses\Pages;

use App\Filament\Resources\ControlResponses\ControlResponseResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListControlResponses extends ListRecords
{
    protected static string $resource = ControlResponseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
