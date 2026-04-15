<?php

namespace App\Filament\Resources\VacationRequests\Pages;

use App\Filament\Resources\VacationRequests\VacationRequestResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListVacationRequests extends ListRecords
{
    protected static string $resource = VacationRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('backToSite')
                ->label('На сайт')
                ->icon('heroicon-m-arrow-left')
                ->url(url('/'))
                ->color('gray')
                ->outlined(),

            CreateAction::make(),
        ];
    }
}