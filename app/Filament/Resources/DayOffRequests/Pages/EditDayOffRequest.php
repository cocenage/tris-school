<?php

namespace App\Filament\Resources\DayOffRequests\Pages;

use App\Filament\Resources\DayOffRequests\DayOffRequestResource;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditDayOffRequest extends EditRecord
{
    protected static string $resource = DayOffRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('back')
                ->label('Назад')
                ->icon('heroicon-m-arrow-left')
                ->url(DayOffRequestResource::getUrl('index'))
                ->color('gray')
                ->outlined(),
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}
