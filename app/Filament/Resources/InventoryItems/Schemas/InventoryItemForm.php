<?php

namespace App\Filament\Resources\InventoryItems\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class InventoryItemForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Товар')
                ->schema([
                    TextInput::make('name')
                        ->label('Название')
                        ->required()
                        ->maxLength(255),

                    TextInput::make('sort_order')
                        ->label('Сортировка')
                        ->numeric()
                        ->default(0),

                    Toggle::make('is_active')
                        ->label('Активно')
                        ->default(true),
                ])
                ->columns(2),
        ]);
    }
}