<?php

namespace App\Filament\Resources\InventoryItems\Schemas;

use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class InventoryItemForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('main.name')
                    ->label('Название')
                    ->required()
                    ->maxLength(255),

                TextInput::make('main.quantity')
                    ->label('Общее количество')
                    ->numeric()
                    ->default(0)
                    ->minValue(0)
                    ->required(),

                Toggle::make('active')
                    ->label('Активен')
                    ->default(true),

                Repeater::make('main.variants')
                    ->label('Варианты')
                    ->schema([
                        TextInput::make('type')
                            ->label('Тип')
                            ->maxLength(255),

                        TextInput::make('size')
                            ->label('Размер')
                            ->maxLength(255),

                        TextInput::make('quantity')
                            ->label('Количество')
                            ->numeric()
                            ->default(0)
                            ->minValue(0)
                            ->required(),
                    ])
                    ->defaultItems(0)
                    ->addActionLabel('Добавить вариант')
                    ->reorderable(false)
                    ->collapsible()
                    ->cloneable()
                    ->columnSpanFull(),
            ]);
    }
}