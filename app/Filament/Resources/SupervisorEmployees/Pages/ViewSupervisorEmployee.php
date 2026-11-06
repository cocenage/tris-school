<?php

namespace App\Filament\Resources\SupervisorEmployees\Pages;

use App\Filament\Resources\SupervisorEmployees\SupervisorEmployeeResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;

class ViewSupervisorEmployee extends ViewRecord
{
    protected static string $resource = SupervisorEmployeeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('back')
                ->label('Назад к сотрудникам')
                ->icon('heroicon-m-arrow-left')
                ->url(SupervisorEmployeeResource::getUrl('index'))
                ->color('gray')
                ->outlined(),
        ];
    }
}
