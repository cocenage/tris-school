<?php

namespace App\Filament\Resources\TelegramScheduledMessages\Pages;

use App\Filament\Resources\TelegramScheduledMessages\TelegramScheduledMessageResource;
use Filament\Resources\Pages\CreateRecord;

class CreateTelegramScheduledMessage extends CreateRecord
{
    protected static string $resource = TelegramScheduledMessageResource::class;
}
