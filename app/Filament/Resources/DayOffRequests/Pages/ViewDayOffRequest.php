<?php

namespace App\Filament\Resources\DayOffRequests\Pages;

use App\Filament\Resources\DayOffRequests\DayOffRequestResource;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewDayOffRequest extends ViewRecord
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
            Action::make('compact_review')
                ->label('Рассмотреть заявку')
                ->visible(fn (): bool => $this->record->telegram_review_version !== null && (int) $this->record->telegram_review_version === 0 && auth()->user()?->isAdmin())
                ->url(fn (): string => route('structured.admin.show', ['day_off', $this->record->id])),
            EditAction::make()->visible(fn (): bool => $this->record->telegram_review_version === null),
        ];
    }
}
