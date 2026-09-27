<?php

namespace App\Filament\Resources\TelegramChats\Pages;

use App\Filament\Resources\TelegramChats\TelegramChatResource;
use App\Models\TelegramChat;
use App\Services\Telegram\TelegramTopicPresenter;
use Filament\Resources\Pages\ViewRecord;

class ViewTelegramChat extends ViewRecord
{
    protected static string $resource = TelegramChatResource::class;

    public function getSubheading(): ?string
    {
        /** @var TelegramChat $record */
        $record = $this->getRecord();

        return implode(' · ', array_filter([
            app(TelegramTopicPresenter::class)->chatLabel($record),
            $record->type,
            $record->is_enabled ? 'В TRIS включён' : 'В TRIS отключён',
            'Доступ AcademyBot не проверен',
        ]));
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}
