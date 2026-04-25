<?php

namespace App\Filament\Resources\ControlResponses\Pages;

use App\Filament\Resources\ControlResponses\ControlResponseResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewControlResponse extends ViewRecord
{
    protected static string $resource = ControlResponseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
