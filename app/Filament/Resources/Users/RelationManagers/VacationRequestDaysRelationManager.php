<?php

namespace App\Filament\Resources\Users\RelationManagers;

use App\Models\VacationRequestDay;
use App\Services\Calendar\EmployeeWorkCalendarManagementService;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class VacationRequestDaysRelationManager extends RelationManager
{
    protected static string $relationship = 'vacationRequestDays';

    protected static ?string $title = 'История заявок на отпуск';

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
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['request', 'reviewer']))
            ->defaultSort('date', 'desc')
            ->columns([
                TextColumn::make('date')->label('Дата')->date('d.m.Y')->sortable(),
                TextColumn::make('status')->label('Статус')->badge(),
                TextColumn::make('request.reason')->label('Причина заявки')->limit(80)->wrap(),
                TextColumn::make('admin_comment')->label('Комментарий')->placeholder('—')->limit(80)->wrap(),
                TextColumn::make('reviewer.name')->label('Рассмотрел')->placeholder('—'),
                TextColumn::make('reviewed_at')->label('Время решения')->dateTime('d.m.Y H:i')->placeholder('—'),
            ])
            ->recordActions([
                Action::make('cancelCalendarDay')
                    ->label('Отменить день в календаре')
                    ->color('warning')
                    ->visible(fn (VacationRequestDay $record): bool => $record->status === 'approved'
                        && (auth()->user()?->isAdmin() || auth()->user()?->canEditEmployeeCalendars()))
                    ->schema([Textarea::make('reason')->label('Причина')->required()->maxLength(1000)->rows(3)])
                    ->requiresConfirmation()
                    ->action(fn (VacationRequestDay $record, array $data) => app(EmployeeWorkCalendarManagementService::class)->adjust(
                        $this->getOwnerRecord(),
                        ['operation' => 'cancel_request_day', 'date' => $record->date->toDateString(), 'request_type' => 'vacation_request_day', 'request_day_id' => $record->id],
                        auth()->user(),
                        $data['reason'],
                    )),
                Action::make('moveCalendarDay')
                    ->label('Перенести день в календаре')
                    ->visible(fn (VacationRequestDay $record): bool => $record->status === 'approved'
                        && (auth()->user()?->isAdmin() || auth()->user()?->canEditEmployeeCalendars()))
                    ->schema([
                        DatePicker::make('replacement_date')->label('Новая дата')->required()->native(true),
                        Textarea::make('reason')->label('Причина')->required()->maxLength(1000)->rows(3),
                    ])
                    ->requiresConfirmation()
                    ->action(fn (VacationRequestDay $record, array $data) => app(EmployeeWorkCalendarManagementService::class)->adjust(
                        $this->getOwnerRecord(),
                        ['operation' => 'move_request_day', 'date' => $record->date->toDateString(), 'replacement_date' => $data['replacement_date'], 'request_type' => 'vacation_request_day', 'request_day_id' => $record->id],
                        auth()->user(),
                        $data['reason'],
                    )),
            ])
            ->headerActions([])
            ->toolbarActions([]);
    }
}
