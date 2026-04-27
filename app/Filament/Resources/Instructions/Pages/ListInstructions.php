<?php

namespace App\Filament\Resources\Instructions\Pages;

use App\Filament\Resources\Instructions\InstructionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListInstructions extends ListRecords
{
    protected static string $resource = InstructionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
