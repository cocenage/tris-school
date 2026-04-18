<?php

namespace App\Filament\Resources\SalaryQuestions\Pages;

use App\Filament\Resources\SalaryQuestions\SalaryQuestionResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewSalaryQuestion extends ViewRecord
{
    protected static string $resource = SalaryQuestionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
