<?php

namespace App\Filament\Resources\InventoryRequests\Pages;

use App\Filament\Resources\InventoryRequests\InventoryRequestResource;
use Filament\Resources\Pages\CreateRecord;

class CreateInventoryRequest extends CreateRecord
{
    protected static string $resource = InventoryRequestResource::class;
}
