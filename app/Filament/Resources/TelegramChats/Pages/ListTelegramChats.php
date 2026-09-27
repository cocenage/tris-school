<?php

namespace App\Filament\Resources\TelegramChats\Pages;

use App\Filament\Resources\TelegramChats\TelegramChatResource;
use Filament\Resources\Pages\ListRecords;

class ListTelegramChats extends ListRecords
{
    protected static string $resource = TelegramChatResource::class;
}
