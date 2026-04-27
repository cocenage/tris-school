<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

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
        $data['access_finance'] = $this->record->hasPanelAccess('finance');
        $data['access_education'] = $this->record->hasPanelAccess('education');

        $data['calendar_workflow'] = $this->record->hasCalendarTypeAccess('workflow');
        $data['calendar_finance'] = $this->record->hasCalendarTypeAccess('finance');
        $data['calendar_holiday'] = $this->record->hasCalendarTypeAccess('holiday');
        $data['calendar_peak'] = $this->record->hasCalendarTypeAccess('peak');
        $data['calendar_vacation'] = $this->record->hasCalendarTypeAccess('vacation');
        $data['calendar_strike'] = $this->record->hasCalendarTypeAccess('strike');

        return $data;
    }
    protected function afterSave(): void
    {
        $this->savePanelAccess('finance', (bool) ($this->data['access_finance'] ?? false));
        $this->savePanelAccess('education', (bool) ($this->data['access_education'] ?? false));

        $this->saveCalendarTypeAccess('workflow', (bool) ($this->data['calendar_workflow'] ?? false));
        $this->saveCalendarTypeAccess('finance', (bool) ($this->data['calendar_finance'] ?? false));
        $this->saveCalendarTypeAccess('holiday', (bool) ($this->data['calendar_holiday'] ?? false));
        $this->saveCalendarTypeAccess('peak', (bool) ($this->data['calendar_peak'] ?? false));
        $this->saveCalendarTypeAccess('vacation', (bool) ($this->data['calendar_vacation'] ?? false));
        $this->saveCalendarTypeAccess('strike', (bool) ($this->data['calendar_strike'] ?? false));
    }

    protected function saveCalendarTypeAccess(string $type, bool $canView): void
    {
        $this->record->calendarTypeAccesses()->updateOrCreate(
            ['type' => $type],
            ['can_view' => $canView],
        );
    }
}
