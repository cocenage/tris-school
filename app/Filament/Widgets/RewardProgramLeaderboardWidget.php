<?php

namespace App\Filament\Widgets;

use Filament\Widgets\Widget;

class RewardProgramLeaderboardWidget extends Widget
{
    protected string $view = 'filament.widgets.reward-program-leaderboard-widget';

        public ?\App\Models\RewardProgram $record = null;
}
