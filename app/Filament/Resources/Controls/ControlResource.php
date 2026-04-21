<?php

namespace App\Filament\Resources\Controls;

use App\Filament\Resources\Controls\Pages\CreateControl;
use App\Filament\Resources\Controls\Pages\EditControl;
use App\Filament\Resources\Controls\Pages\ListControls;
use App\Filament\Resources\Controls\Pages\ViewControl;
use App\Filament\Resources\Controls\Schemas\ControlForm;
use App\Filament\Resources\Controls\Schemas\ControlInfolist;
use App\Filament\Resources\Controls\Tables\ControlsTable;
use App\Models\Control;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class ControlResource extends Resource
{
    protected static ?string $model = Control::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $navigationLabel = 'Формы контролей';
    protected static ?string $modelLabel = 'Контроль';
    protected static ?string $pluralModelLabel = 'Контроли';

    public static function getNavigationGroup(): ?string
    {
        return 'Формы контроля и коучинга';
    }

    public static function form(Schema $schema): Schema
    {
        return ControlForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return ControlInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ControlsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListControls::route('/'),
            'create' => CreateControl::route('/create'),
            'view' => ViewControl::route('/{record}'),
            'edit' => EditControl::route('/{record}/edit'),
        ];
    }
}