<?php

namespace App\Filament\Resources\Apartments;

use App\Filament\Resources\Apartments\Pages\CreateApartment;
use App\Filament\Resources\Apartments\Pages\EditApartment;
use App\Filament\Resources\Apartments\Pages\ListApartments;
use App\Filament\Resources\Apartments\Pages\ViewApartment;
use App\Filament\Resources\Apartments\RelationManagers\InformationAttachmentsRelationManager;
use App\Filament\Resources\Apartments\RelationManagers\InformationSectionsRelationManager;
use App\Filament\Resources\Apartments\RelationManagers\AccessRelationManager;
use App\Filament\Resources\Apartments\Schemas\ApartmentForm;
use App\Filament\Resources\Apartments\Schemas\ApartmentInfolist;
use App\Filament\Resources\Apartments\Tables\ApartmentsTable;
use App\Models\Apartment;
use App\Services\Apartments\ApartmentAccessService;
use BackedEnum;
use Illuminate\Database\Eloquent\Builder;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class ApartmentResource extends Resource
{
    protected static ?string $model = Apartment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHomeModern;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $navigationLabel = 'Квартиры';
    protected static ?string $modelLabel = 'Квартира';
    protected static ?string $pluralModelLabel = 'Квартиры';

    public static function getNavigationGroup(): ?string
    {
        return 'Формы контроля и коучинга';
    }

    public static function form(Schema $schema): Schema
    {
        return ApartmentForm::configure($schema);
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = auth()->user();

        if ($user && ! app(ApartmentAccessService::class)->canManage($user)) {
            return app(ApartmentAccessService::class)->visibleQuery($user);
        }

        return $query;
    }

    public static function infolist(Schema $schema): Schema
    {
        return ApartmentInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ApartmentsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            InformationSectionsRelationManager::class,
            InformationAttachmentsRelationManager::class,
            AccessRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListApartments::route('/'),
            'create' => CreateApartment::route('/create'),
            'view' => ViewApartment::route('/{record}'),
            'edit' => EditApartment::route('/{record}/edit'),
        ];
    }
}
