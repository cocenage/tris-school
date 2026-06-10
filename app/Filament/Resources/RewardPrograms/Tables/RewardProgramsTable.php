<?php

namespace App\Filament\Resources\RewardPrograms\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class RewardProgramsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Название')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('starts_at')
                    ->label('Начало')
                    ->date('d.m.Y')
                    ->sortable(),

                TextColumn::make('ends_at')
                    ->label('Конец')
                    ->date('d.m.Y')
                    ->sortable(),

                TextColumn::make('pointEvents_count')
                    ->label('Начислений')
                    ->counts('pointEvents')
                    ->sortable(),

                IconColumn::make('is_active')
                    ->label('Активна')
                    ->boolean(),
            ])
            ->defaultSort('created_at', 'desc')
            ->actions([
                EditAction::make(),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}