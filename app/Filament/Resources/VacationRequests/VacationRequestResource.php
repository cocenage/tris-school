<?php

namespace App\Filament\Resources\VacationRequests;

use App\Filament\Resources\VacationRequests\Pages\CreateVacationRequest;
use App\Filament\Resources\VacationRequests\Pages\EditVacationRequest;
use App\Filament\Resources\VacationRequests\Pages\ListVacationRequests;
use App\Filament\Resources\VacationRequests\Pages\ViewVacationRequest;
use App\Filament\Resources\VacationRequests\Schemas\VacationRequestForm;
use App\Filament\Resources\VacationRequests\Schemas\VacationRequestInfolist;
use App\Filament\Resources\VacationRequests\Tables\VacationRequestsTable;
use App\Models\VacationRequest;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class VacationRequestResource extends Resource
{
    protected static ?string $model = VacationRequest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $recordTitleAttribute = 'id';

    protected static ?string $navigationLabel = 'Отпуск';
    protected static ?string $modelLabel = 'Отпуск';
    protected static ?string $pluralModelLabel = 'Заявки формы отпуска';
    protected static string|\UnitEnum|null $navigationGroup = 'Заявки';

    public static function form(Schema $schema): Schema
    {
        return VacationRequestForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return VacationRequestInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return VacationRequestsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            \App\Filament\Resources\VacationRequests\RelationManagers\DaysRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListVacationRequests::route('/'),
            'create' => CreateVacationRequest::route('/create'),
            'view' => ViewVacationRequest::route('/{record}'),
            'edit' => EditVacationRequest::route('/{record}/edit'),
        ];
    }

    public static function getNavigationBadge(): ?string
    {
        return (string) static::getModel()::count();
    }
}