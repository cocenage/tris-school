<?php

namespace App\Filament\Resources\DayOffRequests;

use App\Filament\Resources\DayOffRequests\Pages\CreateDayOffRequest;
use App\Filament\Resources\DayOffRequests\Pages\EditDayOffRequest;
use App\Filament\Resources\DayOffRequests\Pages\ListDayOffRequests;
use App\Filament\Resources\DayOffRequests\Pages\ViewDayOffRequest;
use App\Filament\Resources\DayOffRequests\RelationManagers\DaysRelationManager;
use App\Filament\Resources\DayOffRequests\Schemas\DayOffRequestForm;
use App\Filament\Resources\DayOffRequests\Schemas\DayOffRequestInfolist;
use App\Filament\Resources\DayOffRequests\Tables\DayOffRequestsTable;
use App\Models\DayOffRequest;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class DayOffRequestResource extends Resource
{
    protected static ?string $model = DayOffRequest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static string|UnitEnum|null $navigationGroup = 'Заявки';

    protected static ?int $navigationSort = 3;

    protected static ?string $recordTitleAttribute = 'user_name';

    protected static ?string $navigationLabel = 'Выходные дни';

    protected static ?string $modelLabel = 'заявка на выходной';

    protected static ?string $pluralModelLabel = 'заявки на выходные';

    public static function form(Schema $schema): Schema
    {
        return DayOffRequestForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return DayOffRequestInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return DayOffRequestsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            DaysRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDayOffRequests::route('/'),
            'create' => CreateDayOffRequest::route('/create'),
            'view' => ViewDayOffRequest::route('/{record}'),
            'edit' => EditDayOffRequest::route('/{record}/edit'),
        ];
    }

    public static function getNavigationBadge(): ?string
    {
        $pendingCount = static::getModel()::query()
            ->where('status', 'pending')
            ->count();

        return $pendingCount > 0 ? (string) $pendingCount : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }
}