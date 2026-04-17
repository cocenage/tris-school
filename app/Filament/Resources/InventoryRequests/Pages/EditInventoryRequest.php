<?php

namespace App\Filament\Resources\InventoryRequests\Pages;

use App\Filament\Resources\InventoryRequests\InventoryRequestResource;
use Filament\Resources\Pages\EditRecord;

class EditInventoryRequest extends EditRecord
{
    protected static string $resource = InventoryRequestResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['processed_by'] = auth()->id();

        if (
            filled($data['admin_comment'] ?? null)
            || in_array($this->record->status, ['issued', 'partially_issued', 'cancelled'], true)
        ) {
            $data['processed_at'] = now();
        }

        return $data;
    }
}