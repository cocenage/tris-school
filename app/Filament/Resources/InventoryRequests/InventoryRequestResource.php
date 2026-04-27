<?php

namespace App\Filament\Resources\InventoryRequests;

use App\Filament\Resources\InventoryRequests\Pages\EditInventoryRequest;
use App\Filament\Resources\InventoryRequests\Pages\ListInventoryRequests;
use App\Filament\Resources\InventoryRequests\Pages\ViewInventoryRequest;
use App\Filament\Resources\InventoryRequests\RelationManagers\LinesRelationManager;
use App\Filament\Resources\InventoryRequests\Schemas\InventoryRequestForm;
use App\Filament\Resources\InventoryRequests\Schemas\InventoryRequestInfolist;
use App\Filament\Resources\InventoryRequests\Tables\InventoryRequestsTable;
use App\Models\InventoryRequest;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class InventoryRequestResource extends Resource
{
    protected static ?string $model = InventoryRequest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static string|UnitEnum|null $navigationGroup = 'Заявки';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'user_name';

    protected static ?string $navigationLabel = 'Запрос инвентаря';

    protected static ?string $modelLabel = 'заявка на инвентарь';

    protected static ?string $pluralModelLabel = 'заявки на инвентарь';

    public static function form(Schema $schema): Schema
    {
        return InventoryRequestForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return InventoryRequestInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return InventoryRequestsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            LinesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListInventoryRequests::route('/'),
            'view' => ViewInventoryRequest::route('/{record}'),
            'edit' => EditInventoryRequest::route('/{record}/edit'),
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