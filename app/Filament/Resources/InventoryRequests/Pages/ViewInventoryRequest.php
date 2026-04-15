<?php

namespace App\Filament\Resources\InventoryRequests\Pages;

use App\Filament\Resources\InventoryRequests\InventoryRequestResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewInventoryRequest extends ViewRecord
{
    protected static string $resource = InventoryRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
