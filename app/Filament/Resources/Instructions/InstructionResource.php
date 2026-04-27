<?php

namespace App\Filament\Resources\Instructions;

use App\Filament\Resources\Instructions\Pages\CreateInstruction;
use App\Filament\Resources\Instructions\Pages\EditInstruction;
use App\Filament\Resources\Instructions\Pages\ListInstructions;
use App\Filament\Resources\Instructions\Schemas\InstructionForm;
use App\Filament\Resources\Instructions\Tables\InstructionsTable;
use App\Models\Instruction;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class InstructionResource extends Resource
{
    protected static ?string $model = Instruction::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    protected static ?string $navigationLabel = 'Статьи';

    protected static ?string $modelLabel = 'инструкция';

    protected static ?string $pluralModelLabel = 'Инструкции';

    public static function form(Schema $schema): Schema
    {
        return InstructionForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return InstructionsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListInstructions::route('/'),
            'create' => CreateInstruction::route('/create'),
            'edit' => EditInstruction::route('/{record}/edit'),
        ];
    }
}