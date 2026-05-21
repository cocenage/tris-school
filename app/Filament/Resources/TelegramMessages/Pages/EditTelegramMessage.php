<?php

namespace App\Filament\Resources\TelegramMessages\Pages;

use App\Filament\Resources\TelegramMessages\TelegramMessageResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditTelegramMessage extends EditRecord
{
    protected static string $resource = TelegramMessageResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
