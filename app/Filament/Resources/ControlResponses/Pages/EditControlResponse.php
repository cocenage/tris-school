<?php

namespace App\Filament\Resources\ControlResponses\Pages;

use App\Filament\Resources\ControlResponses\ControlResponseResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditControlResponse extends EditRecord
{
    protected static string $resource = ControlResponseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}
