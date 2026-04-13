<?php

namespace App\Filament\Resources\CalendarEvents\Tables;

use App\Filament\Resources\CalendarEvents\CalendarEventResource;
use App\Models\CalendarEvent;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

use Illuminate\Support\HtmlString;
class CalendarEventsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->recordUrl(fn($record) => CalendarEventResource::getUrl('edit', ['record' => $record]))
            ->defaultSort('start_date', 'desc')
            ->columns([
                TextColumn::make('title')
                    ->label('Название')
                    ->searchable()
                    ->sortable()
                    ->weight('600'),

                TextColumn::make('type')
                    ->label('Тип')
                    ->html()
                    ->formatStateUsing(function (string $state): HtmlString {
                        $label = CalendarEvent::typeOptions()[$state] ?? $state;

                        $style = match ($state) {
                            CalendarEvent::TYPE_WORKFLOW => 'background:#CFE8FF;color:#111111;',
                            CalendarEvent::TYPE_FINANCE => 'background:#CBEED9;color:#111111;',
                            CalendarEvent::TYPE_HOLIDAY => 'background:#F3B8B8;color:#111111;',
                            CalendarEvent::TYPE_PEAK => 'background:#F3E69C;color:#111111;',
                            CalendarEvent::TYPE_STRIKE => 'background:#F4C9A8;color:#111111;',
                            default => 'background:#E9E9E9;color:#111111;',
                        };

                        return new HtmlString(
                            '<span style="'
                            . $style .
                            'display:inline-flex;align-items:center;border-radius:9999px;padding:4px 10px;font-size:12px;font-weight:600;line-height:1.2;">'
                            . e($label) .
                            '</span>'
                        );
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
                    ->formatStateUsing(fn(string $state): string => CalendarEvent::repeatOptions()[$state] ?? $state)
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
                    ->default('1')
                    ->label('Активно'),
            ])
            ->actions([
                ActionGroup::make([
                    ViewAction::make(),
                    EditAction::make(),
                    DeleteAction::make(),
                ]),
            ])
            ->bulkActions([]);
    }
}