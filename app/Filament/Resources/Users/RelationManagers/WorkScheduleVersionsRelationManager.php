<?php

namespace App\Filament\Resources\Users\RelationManagers;

use App\Services\Calendar\EmployeeWorkCalendarManagementService;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class WorkScheduleVersionsRelationManager extends RelationManager
{
    protected static string $relationship = 'workScheduleVersions';

    protected static ?string $title = 'История графика';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->isAdmin() || auth()->user()?->canViewAllEmployeeCalendars() || false;
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('effective_from', 'desc')
            ->columns([
                TextColumn::make('effective_from')->label('Действует с')->date('d.m.Y')->sortable(),
                TextColumn::make('version_type')->label('Изменение')->formatStateUsing(fn (?string $state) => match ($state) {
                    'activation_snapshot' => 'Снимок при запуске',
                    'employment_start_recorded' => 'Зафиксировано начало работы',
                    'employment_start_change' => 'Изменено начало работы',
                    default => 'Изменение графика',
                }),
                TextColumn::make('weekend_days')->label('Регулярные выходные')->formatStateUsing(fn ($state) => collect($state ?? [])->map(fn ($day) => [
                    1 => 'Пн', 2 => 'Вт', 3 => 'Ср', 4 => 'Чт', 5 => 'Пт', 6 => 'Сб', 7 => 'Вс',
                ][$day] ?? (string) $day)->implode(', ') ?: 'Не указаны'),
                TextColumn::make('actor_name_snapshot')->label('Кто изменил')->placeholder('Системный снимок'),
                TextColumn::make('reason')->label('Причина')->wrap(),
            ])
            ->headerActions([
                Action::make('changeSchedule')
                    ->label('Изменить график')
                    ->visible(fn (): bool => auth()->user()?->isAdmin() || auth()->user()?->canEditEmployeeCalendars() || false)
                    ->schema([
                        DatePicker::make('effective_from')->label('Дата вступления в силу')->required()->minDate(now()->startOfDay())->native(true),
                        CheckboxList::make('weekend_days')->label('Регулярные выходные')->options([
                            1 => 'Понедельник', 2 => 'Вторник', 3 => 'Среда', 4 => 'Четверг', 5 => 'Пятница', 6 => 'Суббота', 7 => 'Воскресенье',
                        ])->columns(2),
                        Textarea::make('reason')->label('Причина')->required()->maxLength(1000)->rows(3),
                    ])
                    ->action(function (array $data): void {
                        app(EmployeeWorkCalendarManagementService::class)->changeSchedule(
                            $this->getOwnerRecord(),
                            ['weekend_days' => $data['weekend_days'] ?? []],
                            Carbon::parse($data['effective_from'], config('app.timezone', 'Europe/Rome')),
                            auth()->user(),
                            $data['reason'],
                        );
                    }),
            ])
            ->recordActions([])
            ->toolbarActions([]);
    }
}
