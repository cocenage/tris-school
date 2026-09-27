<?php

namespace App\Filament\Resources\TelegramScheduledMessages\Pages;

use App\Filament\Resources\TelegramScheduledMessages\TelegramScheduledMessageResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditTelegramScheduledMessage extends EditRecord
{
    protected static string $resource = TelegramScheduledMessageResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
