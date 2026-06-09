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
                TextColumn::make('cleaner.name')
                    ->label('Имя')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('result_zone_label')
                    ->label('Цвет')
                    ->badge()
                    ->color(fn (ControlResponse $record): string => $record->result_zone_color),

                TextColumn::make('inspection_date')
                    ->label('Дата контроля')
                    ->date('d.m.Y')
                    ->sortable(),

                TextColumn::make('apartment.name')
                    ->label('Квартира')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('supervisor.name')
                    ->label('Кто проверил')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('control.name')
                    ->label('Форма')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('sent_at')
                    ->label('Отправлено')
                    ->dateTime('d.m.Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
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