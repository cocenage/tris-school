<?php

namespace App\Filament\Resources\SupervisorEmployees;

use App\Filament\Resources\SupervisorEmployees\Pages\ListSupervisorEmployees;
use App\Filament\Resources\SupervisorEmployees\Pages\ViewSupervisorEmployee;
use App\Filament\Resources\SupervisorEmployees\Schemas\SupervisorEmployeeInfolist;
use App\Filament\Resources\SupervisorEmployees\Tables\SupervisorEmployeesTable;
use App\Filament\Resources\Users\RelationManagers\CalendarAdjustmentsRelationManager;
use App\Filament\Resources\Users\RelationManagers\DayOffRequestDaysRelationManager;
use App\Filament\Resources\Users\RelationManagers\VacationRequestDaysRelationManager;
use App\Filament\Resources\Users\RelationManagers\WorkScheduleVersionsRelationManager;
use App\Models\User;
use App\Services\Authorization\SupervisorPanelAccess;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class SupervisorEmployeeResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::Users;

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $navigationLabel = 'Сотрудники';

    protected static ?string $modelLabel = 'сотрудник';

    protected static ?string $pluralModelLabel = 'сотрудники';

    public static function getNavigationGroup(): ?string
    {
        return 'Сотрудники и графики';
    }

    public static function canViewAny(): bool
    {
        return Filament::getCurrentPanel()?->getId() === 'education'
            && app(SupervisorPanelAccess::class)->canUseSupervisorPanel(auth()->user());
    }

    public static function canView(Model $record): bool
    {
        return static::canViewAny()
            && $record instanceof User
            && $record->status === 'approved'
            && $record->is_active
            && in_array($record->role, ['cleaner', 'supervisor'], true);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('status', 'approved')
            ->where('is_active', true)
            ->whereIn('role', ['cleaner', 'supervisor'])
            ->orderBy('name');
    }

    public static function infolist(Schema $schema): Schema
    {
        return SupervisorEmployeeInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SupervisorEmployeesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            WorkScheduleVersionsRelationManager::class,
            CalendarAdjustmentsRelationManager::class,
            DayOffRequestDaysRelationManager::class,
            VacationRequestDaysRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSupervisorEmployees::route('/'),
            'view' => ViewSupervisorEmployee::route('/{record}'),
        ];
    }
}
