<?php

namespace App\Filament\Resources\RewardPrograms\Pages;

use App\Filament\Resources\RewardPrograms\RewardProgramResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditRewardProgram extends EditRecord
{
    protected static string $resource = RewardProgramResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
