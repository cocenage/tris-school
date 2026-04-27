<?php

namespace App\Filament\Resources\InstructionCategories;

use App\Filament\Resources\InstructionCategories\Pages\CreateInstructionCategory;
use App\Filament\Resources\InstructionCategories\Pages\EditInstructionCategory;
use App\Filament\Resources\InstructionCategories\Pages\ListInstructionCategories;
use App\Filament\Resources\InstructionCategories\Schemas\InstructionCategoryForm;
use App\Filament\Resources\InstructionCategories\Tables\InstructionCategoriesTable;
use App\Models\InstructionCategory;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class InstructionCategoryResource extends Resource
{
    protected static ?string $model = InstructionCategory::class;

    protected static ?string $navigationLabel = 'Категории';
protected static ?string $modelLabel = 'категория';
protected static ?string $pluralModelLabel = 'Категории инструкций';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function form(Schema $schema): Schema
    {
        return InstructionCategoryForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return InstructionCategoriesTable::configure($table);
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
            'index' => ListInstructionCategories::route('/'),
            'create' => CreateInstructionCategory::route('/create'),
            'edit' => EditInstructionCategory::route('/{record}/edit'),
        ];
    }
}
