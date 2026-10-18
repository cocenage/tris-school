<?php

namespace App\Filament\Resources\VacationRequests\Pages;

use App\Filament\Resources\VacationRequests\VacationRequestResource;
use App\Services\Forms\VacationRequestTelegramService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditVacationRequest extends EditRecord
{
    protected static string $resource = VacationRequestResource::class;

    protected function afterSave(): void
    {
        app(VacationRequestTelegramService::class)->syncSourceMessage($this->record);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('back')
                ->label('Назад')
                ->icon('heroicon-m-arrow-left')
                ->url(VacationRequestResource::getUrl('index'))
                ->color('gray')
                ->outlined(),

            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}
