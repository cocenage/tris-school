<?php

namespace App\Filament\Resources\InventoryItems\Tables;

use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class InventoryItemsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('name')
                    ->label('Название')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('quantity')
                    ->label('Количество')
                    ->sortable(),

                TextColumn::make('variants_count')
                    ->label('Вариантов')
                    ->state(function ($record): int {
                        return count($record->variants ?? []);
                    }),

                TextColumn::make('active')
                    ->label('Активен')
                    ->badge()
                    ->formatStateUsing(fn (bool $state): string => $state ? 'Да' : 'Нет')
                    ->color(fn (bool $state): string => $state ? 'success' : 'gray')
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label('Создано')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ]);
    }
}