<?php

namespace App\Filament\Resources\InventoryRequests\Pages;

use App\Filament\Resources\InventoryRequests\InventoryRequestResource;
use App\Services\Forms\InventoryRequestTelegramService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditInventoryRequest extends EditRecord
{
    protected static string $resource = InventoryRequestResource::class;

    protected function handleRecordUpdate(\Illuminate\Database\Eloquent\Model $record, array $data): \Illuminate\Database\Eloquent\Model
    {
        abort_unless($record->fresh()?->telegram_review_version === null, 409, 'Используйте общую форму рассмотрения.');
        return parent::handleRecordUpdate($record, $data);
    }

    protected function afterSave(): void
    {
        app(InventoryRequestTelegramService::class)->syncSourceMessage($this->record);
    }

    protected function getFormActions(): array
    {
        return [
            ...parent::getFormActions(),

            Action::make('back')
                ->icon('heroicon-m-arrow-left')
                ->label('Назад')
                ->color('gray')
                ->outlined()
                ->url(InventoryRequestResource::getUrl('index')),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make()->visible(fn (): bool => $this->record->telegram_review_version === null),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['processed_by'] = auth()->id();

        if (
            filled($data['admin_comment'] ?? null)
            || in_array($this->record->status, ['issued', 'partially_issued', 'cancelled'], true)
        ) {
            $data['processed_at'] = now();
        }

        $data['answered_at'] = now();
        $data['answer_seen_at'] = null;

        return $data;

    }
}
