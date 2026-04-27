<?php

namespace App\Filament\Resources\ScheduleQuestions\Pages;

use App\Filament\Resources\ScheduleQuestions\ScheduleQuestionResource;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditScheduleQuestion extends EditRecord
{
    protected static string $resource = ScheduleQuestionResource::class;

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

        $data['answered_at'] = now();
        $data['answer_seen_at'] = null;

        return $data;
    }

    protected function getFormActions(): array
    {
        return [
            ...parent::getFormActions(),

            Action::make('back')
                ->label('Назад')
                ->color('gray')
                ->outlined()
                ->url(ScheduleQuestionResource::getUrl('index')),
        ];
    }
}
