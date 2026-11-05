<?php

namespace App\Filament\Resources\Users\RelationManagers;

use App\Models\EmployeeCalendarAdjustment;
use App\Services\Calendar\EmployeeWorkCalendarManagementService;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class CalendarAdjustmentsRelationManager extends RelationManager
{
    protected static string $relationship = 'calendarAdjustments';

    protected static ?string $title = 'Корректировки календаря';

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
            ->modifyQueryUsing(fn ($query) => $query->withCount('reversals'))
            ->defaultSort('starts_on', 'desc')
            ->columns([
                TextColumn::make('starts_on')->label('Дата с')->date('d.m.Y')->sortable(),
                TextColumn::make('ends_on')->label('По')->date('d.m.Y')->placeholder('Один день'),
                TextColumn::make('operation')->label('Действие')->formatStateUsing(fn (?string $state) => match ($state) {
                    'set_state' => 'Изменение состояния',
                    'cancel_request_day' => 'Отмена дня заявки',
                    'move_request_day' => 'Перенос дня заявки',
                    'reverse' => 'Обратная корректировка',
                    default => $state ?? '—',
                }),
                TextColumn::make('category')->label('Категория')->placeholder('—'),
                TextColumn::make('actor_name_snapshot')->label('Кто изменил')->placeholder('—'),
                TextColumn::make('reason')->label('Причина')->wrap(),
            ])
            ->headerActions([
                Action::make('addAdjustment')
                    ->label('Добавить корректировку')
                    ->visible(fn (): bool => auth()->user()?->isAdmin() || auth()->user()?->canEditEmployeeCalendars() || false)
                    ->schema([
                        DatePicker::make('date')->label('Дата')->required()->native(true),
                        DatePicker::make('ends_on')->label('По дату включительно')->native(true),
                        Select::make('state')->label('Состояние')->options([
                            'planned_work' => 'Рабочий день по графику',
                            'regular_day_off' => 'Регулярный выходной',
                            'approved_absence' => 'Одобренное отсутствие',
                            'unknown_schedule' => 'График не зафиксирован',
                            'not_employed' => 'До начала работы',
                        ])->required()->native(false),
                        Select::make('category')->label('Категория')->options([
                            'day_off' => 'Выходной',
                            'vacation' => 'Отпуск',
                            'unpaid_leave' => 'Неоплачиваемый отпуск',
                            'other' => 'Другое',
                        ])->native(false),
                        Textarea::make('reason')->label('Причина')->required()->maxLength(1000)->rows(3),
                    ])
                    ->action(function (array $data): void {
                        app(EmployeeWorkCalendarManagementService::class)->adjust(
                            $this->getOwnerRecord(),
                            [
                                'operation' => 'set_state',
                                'date' => $data['date'],
                                'ends_on' => $data['ends_on'] ?? null,
                                'state' => $data['state'],
                                'category' => $data['category'] ?? null,
                            ],
                            auth()->user(),
                            $data['reason'],
                        );
                    }),
            ])
            ->recordActions([
                Action::make('reverse')
                    ->label('Отменить корректировку')
                    ->color('warning')
                    ->visible(fn (EmployeeCalendarAdjustment $record): bool => ! $record->reverses_adjustment_id
                        && (int) $record->reversals_count === 0
                        && (auth()->user()?->isAdmin() || auth()->user()?->canEditEmployeeCalendars()))
                    ->schema([Textarea::make('reason')->label('Причина отмены')->required()->maxLength(1000)->rows(3)])
                    ->requiresConfirmation()
                    ->action(fn (EmployeeCalendarAdjustment $record, array $data) => app(EmployeeWorkCalendarManagementService::class)->adjust(
                        $this->getOwnerRecord(),
                        ['operation' => 'reverse', 'adjustment_id' => $record->id],
                        auth()->user(),
                        $data['reason'],
                    )),
            ])
            ->toolbarActions([]);
    }
}
