<?php

namespace App\Filament\Resources\TelegramScheduledMessages\Pages;

use App\Filament\Resources\TelegramScheduledMessages\TelegramScheduledMessageResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListTelegramScheduledMessages extends ListRecords
{
    protected static string $resource = TelegramScheduledMessageResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Добавить напоминание')];
    }
}
