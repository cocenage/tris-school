<?php

namespace App\Filament\Resources\SalaryQuestions\Pages;

use App\Filament\Resources\SalaryQuestions\SalaryQuestionResource;
use App\Models\SalaryQuestion;
use App\Services\Forms\SalaryQuestionDecisionService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use Filament\Actions\Action;
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

    protected function getFormActions(): array
    {
        return [
            ...parent::getFormActions(),

            Action::make('back')
                ->icon('heroicon-m-arrow-left')
                ->label('Назад')
                ->color('gray')
                ->outlined()
                ->url(SalaryQuestionResource::getUrl('index')),
        ];
    }
    protected function mutateFormDataBeforeSave(array $data): array
    {
        if ($this->record->usesDecisionWorkflow()) {
            return $data;
        }
        $data['reviewed_by'] = auth()->id();
        $data['reviewed_at'] = now();

        $data['answered_at'] = now();
        $data['answer_seen_at'] = null;

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $current = SalaryQuestion::query()->findOrFail($record->getKey());
        if (! $current->usesDecisionWorkflow()) {
            unset($data['decision_version']);
            return parent::handleRecordUpdate($record, $data);
        }
        $status = (string) ($data['status'] ?? $current->status);
        $comment = trim((string) ($data['admin_comment'] ?? ''));
        if ($status === $current->status && $comment === trim((string) $current->admin_comment)) {
            return $current;
        }
        if (! isset($data['decision_version']) || ! is_numeric($data['decision_version'])) {
            throw ValidationException::withMessages(['data.admin_comment' => 'Заявка изменилась. Откройте её заново.']);
        }
        try {
            app(SalaryQuestionDecisionService::class)->decide($current, auth()->user(), $status, $comment, (int) $data['decision_version'], 'filament');
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(['data.admin_comment' => $exception->validator->errors()->first()]);
        }

        return $current->fresh();
    }
}
