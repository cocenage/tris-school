<?php

namespace App\Filament\Resources\ScheduleQuestions\Pages;

use App\Filament\Resources\ScheduleQuestions\ScheduleQuestionResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewScheduleQuestion extends ViewRecord
{
    protected static string $resource = ScheduleQuestionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
