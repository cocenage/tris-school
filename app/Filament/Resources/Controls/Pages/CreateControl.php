<?php

namespace App\Filament\Resources\Controls\Pages;

use App\Filament\Resources\Controls\ControlResource;
use App\Filament\Resources\Controls\Schemas\ControlForm;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;

class CreateControl extends CreateRecord
{
    protected static string $resource = ControlResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['user_id'] = Auth::id();
        $data['main'] = ControlForm::normalizeMainForSave($data['main'] ?? []);

        return $data;
    }
}