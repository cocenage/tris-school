<?php

namespace App\Filament\Resources\RewardPrograms\Pages;

use App\Filament\Resources\RewardPrograms\RewardProgramResource;
use App\Filament\Widgets\RewardProgramLeaderboardWidget;
use Filament\Resources\Pages\EditRecord;

class EditRewardProgram extends EditRecord
{
    protected static string $resource = RewardProgramResource::class;

    public function getHeaderWidgets(): array
    {
        return [
            RewardProgramLeaderboardWidget::class,
        ];
    }
}