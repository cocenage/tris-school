<?php

namespace App\Filament\Resources\MobilityAlerts;

use App\Filament\Resources\MobilityAlerts\Pages\CreateMobilityAlert;
use App\Filament\Resources\MobilityAlerts\Pages\EditMobilityAlert;
use App\Filament\Resources\MobilityAlerts\Pages\ListMobilityAlerts;
use App\Filament\Resources\MobilityAlerts\Schemas\MobilityAlertForm;
use App\Filament\Resources\MobilityAlerts\Tables\MobilityAlertsTable;
use App\Models\MobilityAlert;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class MobilityAlertResource extends Resource
{
    protected static ?string $model = MobilityAlert::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function form(Schema $schema): Schema
    {
        return MobilityAlertForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return MobilityAlertsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMobilityAlerts::route('/'),
            'create' => CreateMobilityAlert::route('/create'),
            'edit' => EditMobilityAlert::route('/{record}/edit'),
        ];
    }
}
