<?php

namespace App\Filament\Resources\InstructionCategories\Pages;

use App\Filament\Resources\InstructionCategories\InstructionCategoryResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditInstructionCategory extends EditRecord
{
    protected static string $resource = InstructionCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
