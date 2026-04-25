<?php

namespace App\Filament\Resources\ControlResponses;

use App\Filament\Resources\ControlResponses\Pages\CreateControlResponse;
use App\Filament\Resources\ControlResponses\Pages\EditControlResponse;
use App\Filament\Resources\ControlResponses\Pages\ListControlResponses;
use App\Filament\Resources\ControlResponses\Pages\ViewControlResponse;
use App\Filament\Resources\ControlResponses\Schemas\ControlResponseForm;
use App\Filament\Resources\ControlResponses\Schemas\ControlResponseInfolist;
use App\Filament\Resources\ControlResponses\Tables\ControlResponsesTable;
use App\Models\ControlResponse;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class ControlResponseResource extends Resource
{
    protected static ?string $model = ControlResponse::class;

   protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

protected static ?string $navigationLabel = 'Ответы контроля';

protected static ?string $modelLabel = 'Ответ контроля';

protected static ?string $pluralModelLabel = 'Ответы контроля';

protected static ?string $recordTitleAttribute = 'apartment';

    public static function form(Schema $schema): Schema
    {
        return ControlResponseForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return ControlResponseInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ControlResponsesTable::configure($table);
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
            'index' => ListControlResponses::route('/'),
            'create' => CreateControlResponse::route('/create'),
            'view' => ViewControlResponse::route('/{record}'),
            'edit' => EditControlResponse::route('/{record}/edit'),
        ];
    }
}
