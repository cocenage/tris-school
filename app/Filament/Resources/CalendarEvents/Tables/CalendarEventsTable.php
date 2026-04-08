<?php

namespace App\Filament\Resources\CalendarEvents\Tables;

use App\Models\CalendarEvent;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class CalendarEventsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('start_date', 'desc')
            ->columns([
                TextColumn::make('title')
                    ->label('Название')
                    ->searchable()
                    ->sortable()
                    ->weight('600'),

                TextColumn::make('type')
                    ->label('Тип')
                    ->formatStateUsing(fn (string $state): string => CalendarEvent::typeOptions()[$state] ?? $state)
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        CalendarEvent::TYPE_WORKFLOW => 'info',
                        CalendarEvent::TYPE_FINANCE => 'success',
                        CalendarEvent::TYPE_HOLIDAY => 'danger',
                        CalendarEvent::TYPE_PEAK => 'warning',
                        default => 'gray',
                    }),

                TextColumn::make('start_date')
                    ->label('Начало')
                    ->date('d.m.Y')
                    ->sortable(),

                TextColumn::make('end_date')
                    ->label('Конец')
                    ->date('d.m.Y')
                    ->placeholder('—'),

                TextColumn::make('repeat_type')
                    ->label('Повторение')
                    ->formatStateUsing(fn (string $state): string => CalendarEvent::repeatOptions()[$state] ?? $state)
                    ->badge()
                    ->color('gray'),

                IconColumn::make('is_active')
                    ->label('Активно')
                    ->boolean(),

                TextColumn::make('priority')
                    ->label('Приоритет')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->label('Тип')
                    ->options(CalendarEvent::typeOptions()),

                SelectFilter::make('repeat_type')
                    ->label('Повторение')
                    ->options(CalendarEvent::repeatOptions()),

                TernaryFilter::make('is_active')
                    ->label('Активно'),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}