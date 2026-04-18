<?php

namespace App\Filament\Resources\SalaryQuestions\Pages;

use App\Filament\Resources\SalaryQuestions\SalaryQuestionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSalaryQuestions extends ListRecords
{
    protected static string $resource = SalaryQuestionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
