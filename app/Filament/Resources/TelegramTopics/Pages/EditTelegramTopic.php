<?php

namespace App\Filament\Resources\TelegramTopics\Pages;

use App\Filament\Resources\TelegramTopics\TelegramTopicResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditTelegramTopic extends EditRecord
{
    protected static string $resource = TelegramTopicResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
