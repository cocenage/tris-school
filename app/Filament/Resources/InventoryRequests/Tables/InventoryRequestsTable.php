<?php

namespace App\Filament\Resources\InventoryRequests\Tables;

use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class InventoryRequestsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('id')
                    ->label('#')
                    ->sortable(),

                TextColumn::make('user.name')
                    ->label('Пользователь')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('status')
                    ->label('Статус')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        'approved' => 'Одобрено',
                        'partially_approved' => 'Частично одобрено',
                        'rejected' => 'Отклонено',
                        default => 'На рассмотрении',
                    }),

                TextColumn::make('items_count')
                    ->counts('items')
                    ->label('Позиций'),

                TextColumn::make('requested_at')
                    ->label('Запрошено')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Статус')
                    ->options([
                        'pending' => 'На рассмотрении',
                        'approved' => 'Одобрено',
                        'partially_approved' => 'Частично одобрено',
                        'rejected' => 'Отклонено',
                    ]),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ]);
    }
}