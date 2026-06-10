<?php

namespace App\Filament\Resources\RewardPrograms;

use App\Filament\Resources\RewardPrograms\Pages\CreateRewardProgram;
use App\Filament\Resources\RewardPrograms\Pages\EditRewardProgram;
use App\Filament\Resources\RewardPrograms\Pages\ListRewardPrograms;
use App\Filament\Resources\RewardPrograms\RelationManagers\PointEventsRelationManager;
use App\Filament\Resources\RewardPrograms\Schemas\RewardProgramForm;
use App\Filament\Resources\RewardPrograms\Tables\RewardProgramsTable;
use App\Models\RewardProgram;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class RewardProgramResource extends Resource
{
    protected static ?string $model = RewardProgram::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGift;

    protected static ?string $navigationLabel = 'Бонусные программы';

    protected static ?string $modelLabel = 'Бонусная программа';

    protected static ?string $pluralModelLabel = 'Бонусные программы';

    public static function form(Schema $schema): Schema
    {
        return RewardProgramForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return RewardProgramsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            PointEventsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRewardPrograms::route('/'),
            'create' => CreateRewardProgram::route('/create'),
            'edit' => EditRewardProgram::route('/{record}/edit'),
        ];
    }
}