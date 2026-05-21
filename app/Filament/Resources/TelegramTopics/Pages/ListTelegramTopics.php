<?php

namespace App\Filament\Resources\TelegramTopics\Pages;

use App\Filament\Resources\TelegramTopics\TelegramTopicResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListTelegramTopics extends ListRecords
{
    protected static string $resource = TelegramTopicResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
