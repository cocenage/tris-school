<?php

namespace App\Filament\Resources\Controls\Pages;

use App\Filament\Resources\Controls\ControlResource;
use App\Filament\Resources\Controls\Schemas\ControlForm;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditControl extends EditRecord
{
    protected static string $resource = ControlResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['main'] = ControlForm::normalizeMainForSave($data['main'] ?? []);

        return $data;
    }

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }

    
}
