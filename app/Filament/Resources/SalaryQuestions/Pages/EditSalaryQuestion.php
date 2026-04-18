<?php

namespace App\Filament\Resources\SalaryQuestions\Pages;

use App\Filament\Resources\SalaryQuestions\SalaryQuestionResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditSalaryQuestion extends EditRecord
{
    protected static string $resource = SalaryQuestionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['reviewed_by'] = auth()->id();
        $data['reviewed_at'] = now();

        return $data;
    }
}
