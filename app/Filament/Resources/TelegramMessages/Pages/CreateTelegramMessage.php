<?php

namespace App\Filament\Resources\TelegramMessages\Pages;

use App\Filament\Resources\TelegramMessages\TelegramMessageResource;
use Filament\Resources\Pages\CreateRecord;

class CreateTelegramMessage extends CreateRecord
{
    protected static string $resource = TelegramMessageResource::class;
}
