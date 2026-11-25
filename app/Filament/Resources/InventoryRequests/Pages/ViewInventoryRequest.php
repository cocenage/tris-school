<?php

namespace App\Filament\Resources\InventoryRequests\Pages;

use App\Filament\Resources\InventoryRequests\InventoryRequestResource;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewInventoryRequest extends ViewRecord
{
    protected static string $resource = InventoryRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('compact_review')
                ->label('Рассмотреть заявку')
                ->visible(fn (): bool => $this->record->telegram_review_version !== null && (int) $this->record->telegram_review_version === 0 && auth()->user()?->isAdmin())
                ->url(fn (): string => route('structured.admin.show', ['inventory', $this->record->id])),
            EditAction::make()->visible(fn (): bool => $this->record->telegram_review_version === null),
        ];
    }
}
