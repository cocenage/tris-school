<?php

namespace App\Filament\Resources\SupervisorEmployees\Pages;

use App\Filament\Resources\SupervisorEmployees\SupervisorEmployeeResource;
use Filament\Resources\Pages\ListRecords;

class ListSupervisorEmployees extends ListRecords
{
    protected static string $resource = SupervisorEmployeeResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
