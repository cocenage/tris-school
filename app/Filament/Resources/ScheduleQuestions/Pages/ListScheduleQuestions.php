<?php

namespace App\Filament\Resources\ScheduleQuestions\Pages;

use App\Filament\Resources\ScheduleQuestions\ScheduleQuestionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListScheduleQuestions extends ListRecords
{
    protected static string $resource = ScheduleQuestionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
