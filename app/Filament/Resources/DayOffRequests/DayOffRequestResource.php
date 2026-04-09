<?php

namespace App\Filament\Resources\DayOffRequests;

use App\Filament\Resources\DayOffRequests\Pages\CreateDayOffRequest;
use App\Filament\Resources\DayOffRequests\Pages\EditDayOffRequest;
use App\Filament\Resources\DayOffRequests\Pages\ListDayOffRequests;
use App\Filament\Resources\DayOffRequests\Pages\ViewDayOffRequest;
use App\Filament\Resources\DayOffRequests\Schemas\DayOffRequestForm;
use App\Filament\Resources\DayOffRequests\Schemas\DayOffRequestInfolist;
use App\Filament\Resources\DayOffRequests\Tables\DayOffRequestsTable;
use App\Models\DayOffRequest;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class DayOffRequestResource extends Resource
{
    protected static ?string $model = DayOffRequest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $recordTitleAttribute = 'user_name';

    protected static ?string $navigationLabel = 'Выходной день';
    protected static ?string $modelLabel = 'Выходной день';
    protected static ?string $pluralModelLabel = 'Заявки формы выходного дня';
    protected static string|\UnitEnum|null $navigationGroup = 'Заявки';

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
            \App\Filament\Resources\DayOffRequests\RelationManagers\DaysRelationManager::class,
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
        return static::getModel()::count();
    }
}
