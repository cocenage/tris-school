<?php

namespace App\Filament\Resources\VacationRequests\Pages;

use App\Filament\Resources\VacationRequests\VacationRequestResource;
use App\Services\Forms\VacationRequestTelegramService;
use Filament\Resources\Pages\CreateRecord;

class CreateVacationRequest extends CreateRecord
{
    protected static string $resource = VacationRequestResource::class;

    protected function afterCreate(): void
    {
        $this->record->recalculateStatus();

        try {
            app(VacationRequestTelegramService::class)->sendCreated($this->record);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}