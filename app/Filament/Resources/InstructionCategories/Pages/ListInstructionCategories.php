<?php

namespace App\Filament\Resources\InstructionCategories\Pages;

use App\Filament\Resources\InstructionCategories\InstructionCategoryResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListInstructionCategories extends ListRecords
{
    protected static string $resource = InstructionCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
