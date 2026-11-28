<?php
namespace App\Filament\Resources\Tasks\Pages;

use App\Filament\Resources\Tasks\TaskResource;
use App\Services\Tasks\FilamentTaskService;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditTask extends EditRecord
{
    protected static string $resource = TaskResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $ids = $this->record->assignees()->pluck('users.id')->all();
        $data['assignee_ids'] = $ids ?: array_filter([$this->record->assigned_to]);

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return app(FilamentTaskService::class)->save(auth()->user(), $data, $record);
    }
}
