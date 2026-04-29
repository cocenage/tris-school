<?php

namespace App\Filament\Resources\ControlResponses\Tables;

use App\Models\ControlResponse;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ControlResponsesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable(),

                TextColumn::make('control.name')
                    ->label('Форма')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('cleaner.name')
                    ->label('Кого проверили')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('supervisor.name')
                    ->label('Кто проверил')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('apartment.name')
                    ->label('Квартира')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('cleaning_date')
                    ->label('Дата уборки')
                    ->date('d.m.Y')
                    ->sortable(),

                TextColumn::make('inspection_date')
                    ->label('Дата проверки')
                    ->date('d.m.Y')
                    ->sortable(),

                TextColumn::make('points')
                    ->label('Баллы')
                    ->state(fn (ControlResponse $record) => "{$record->total_points}/{$record->max_points}"),

                TextColumn::make('score_percent')
                    ->label('%')
                    ->suffix('%')
                    ->sortable(),

                TextColumn::make('result_zone_label')
                    ->label('Зона'),

                TextColumn::make('sent_at')
                    ->label('Отправлено')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
            ])
            ->defaultSort('sent_at', 'desc')
            ->recordUrl(fn (ControlResponse $record) => route(
                'filament.admin.resources.control-responses.view',
                $record
            ))
            ->actions([
                ViewAction::make(),
            ]);
    }
}