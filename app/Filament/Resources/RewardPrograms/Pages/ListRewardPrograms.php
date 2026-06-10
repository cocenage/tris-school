<?php

namespace App\Filament\Resources\RewardPrograms\Pages;

use App\Filament\Resources\RewardPrograms\RewardProgramResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListRewardPrograms extends ListRecords
{
    protected static string $resource = RewardProgramResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
