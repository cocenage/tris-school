<?php

namespace App\Filament\Resources\ScheduleQuestions\Tables;

use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ScheduleQuestionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('user.name')->label('Сотрудник')->searchable()->sortable(),
                TextColumn::make('type')->label('Тип вопроса')->searchable(),
                TextColumn::make('comment')->label('Комментарий')->limit(60)->wrap(),
                TextColumn::make('status')
                    ->label('Статус')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        'reviewed' => 'Рассмотрено',
                        'closed' => 'Закрыто',
                        default => 'На рассмотрении',
                    }),
                TextColumn::make('created_at')->label('Создано')->dateTime('d.m.Y H:i')->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'pending' => 'На рассмотрении',
                        'reviewed' => 'Рассмотрено',
                        'closed' => 'Закрыто',
                    ]),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ]);
    }
}