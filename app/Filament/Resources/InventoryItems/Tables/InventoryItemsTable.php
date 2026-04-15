<?php

namespace App\Filament\Resources\InventoryItems\Tables;

use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class InventoryItemsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->columns([
                TextColumn::make('name')
                    ->label('Название')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('sort_order')
                    ->label('Сорт.'),

                IconColumn::make('is_active')
                    ->label('Активно')
                    ->boolean(),

                TextColumn::make('created_at')
                    ->label('Создано')
                    ->dateTime('d.m.Y H:i'),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }
}