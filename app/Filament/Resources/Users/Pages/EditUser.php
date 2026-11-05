<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use App\Services\Calendar\EmployeeWorkCalendarManagementService;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected ?string $workStartedReason = null;

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
                ->url(UserResource::getUrl('index')),
        ];
    }

    protected function savePanelAccess(string $panel, bool $canAccess): void
    {
        $this->record->panelAccesses()->updateOrCreate(
            ['panel' => $panel],
            ['can_access' => $canAccess],
        );
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['work_started_at_reason'] = null;
        $data['access_finance'] = $this->record->hasPanelAccess('finance');
        $data['access_education'] = $this->record->hasPanelAccess('education');

        $data['calendar_workflow'] = $this->record->hasCalendarTypeAccess('workflow');
        $data['calendar_finance'] = $this->record->hasCalendarTypeAccess('finance');
        $data['calendar_holiday'] = $this->record->hasCalendarTypeAccess('holiday');
        $data['calendar_peak'] = $this->record->hasCalendarTypeAccess('peak', true);
        $data['calendar_vacation'] = $this->record->hasCalendarTypeAccess('vacation', true);
        $data['calendar_strike'] = $this->record->hasCalendarTypeAccess('strike', true);

        $calendarPermission = $this->record->calendarPermission;
        $data['calendar_can_view_all'] = (bool) $calendarPermission?->can_view_all_calendars;
        $data['calendar_can_edit'] = (bool) $calendarPermission?->can_edit;
        $data['calendar_can_export'] = (bool) $calendarPermission?->can_export;

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->workStartedReason = filled($this->data['work_started_at_reason'] ?? null)
            ? trim((string) $this->data['work_started_at_reason'])
            : null;
        unset($data['work_started_at_reason']);

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return DB::transaction(function () use ($record, $data): Model {
            $newWorkStartedAt = filled($data['work_started_at'] ?? null)
                ? Carbon::parse($data['work_started_at'], config('app.timezone', 'Europe/Rome'))
                : null;
            $oldWorkStartedAt = $record->work_started_at;

            if (! $newWorkStartedAt) {
                throw ValidationException::withMessages(['work_started_at' => 'Укажите дату начала работы.']);
            }

            if ($newWorkStartedAt->toDateString() !== $oldWorkStartedAt?->toDateString()) {
                app(EmployeeWorkCalendarManagementService::class)->recordProfileStartDateChange(
                    $record,
                    $oldWorkStartedAt,
                    $newWorkStartedAt,
                    auth()->user(),
                    (string) $this->workStartedReason,
                );
            }

            return parent::handleRecordUpdate($record, $data);
        });
    }

    protected function afterSave(): void
    {
        $this->workStartedReason = null;
        $this->data['work_started_at_reason'] = null;

        $this->savePanelAccess('finance', (bool) ($this->data['access_finance'] ?? false));
        $this->savePanelAccess('education', (bool) ($this->data['access_education'] ?? false));

        $this->saveCalendarTypeAccess('workflow', (bool) ($this->data['calendar_workflow'] ?? false));
        $this->saveCalendarTypeAccess('finance', (bool) ($this->data['calendar_finance'] ?? false));
        $this->saveCalendarTypeAccess('holiday', (bool) ($this->data['calendar_holiday'] ?? false));
        $this->saveCalendarTypeAccess('peak', (bool) ($this->data['calendar_peak'] ?? false));
        $this->saveCalendarTypeAccess('vacation', (bool) ($this->data['calendar_vacation'] ?? false));
        $this->saveCalendarTypeAccess('strike', (bool) ($this->data['calendar_strike'] ?? false));

        if (auth()->user()?->isAdmin() && $this->record->role === 'supervisor') {
            $canViewAll = (bool) ($this->data['calendar_can_view_all'] ?? false);
            $this->record->calendarPermission()->updateOrCreate([], [
                'can_view_all_calendars' => $canViewAll,
                'can_edit' => $canViewAll && (bool) ($this->data['calendar_can_edit'] ?? false),
                'can_export' => $canViewAll && (bool) ($this->data['calendar_can_export'] ?? false),
            ]);
        }
    }

    protected function saveCalendarTypeAccess(string $type, bool $canView): void
    {
        $this->record->calendarTypeAccesses()->updateOrCreate(
            ['type' => $type],
            ['can_view' => $canView],
        );
    }
}
