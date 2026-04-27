<?php

namespace App\Filament\Resources\Instructions\Pages;

use App\Filament\Resources\Instructions\InstructionResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditInstruction extends EditRecord
{
    protected static string $resource = InstructionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }
}
