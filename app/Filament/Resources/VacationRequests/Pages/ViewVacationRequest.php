<?php

namespace App\Filament\Resources\VacationRequests\Pages;

use App\Filament\Resources\VacationRequests\VacationRequestResource;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewVacationRequest extends ViewRecord
{
    protected static string $resource = VacationRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('back')
                ->label('Назад')
                ->icon('heroicon-m-arrow-left')
                ->url(VacationRequestResource::getUrl('index'))
                ->color('gray')
                ->outlined(),

            EditAction::make(),
        ];
    }
}