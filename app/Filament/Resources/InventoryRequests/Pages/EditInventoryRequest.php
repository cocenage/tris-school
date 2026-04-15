<?php

namespace App\Filament\Resources\InventoryRequests\Pages;

use App\Filament\Resources\InventoryRequests\InventoryRequestResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditInventoryRequest extends EditRecord
{
    protected static string $resource = InventoryRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}
