<?php

namespace App\Filament\Resources\InventoryRequests\Pages;

use App\Filament\Resources\InventoryRequests\InventoryRequestResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListInventoryRequests extends ListRecords
{
    protected static string $resource = InventoryRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
