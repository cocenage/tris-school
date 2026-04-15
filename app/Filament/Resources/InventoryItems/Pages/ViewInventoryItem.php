<?php

namespace App\Filament\Resources\InventoryItems\Pages;

use App\Filament\Resources\InventoryItems\InventoryItemResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewInventoryItem extends ViewRecord
{
    protected static string $resource = InventoryItemResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
