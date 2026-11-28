<?php

namespace App\Filament\Support;

use App\Filament\Widgets\WorkspaceOverview;
use Filament\Pages\Dashboard as BaseDashboard;

class Dashboard extends BaseDashboard
{
    public function getWidgets(): array
    {
        return [WorkspaceOverview::class];
    }
}
