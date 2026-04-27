<?php

namespace App\Filament\Resources\CalendarEvents\Tables;

use App\Filament\Resources\CalendarEvents\CalendarEventResource;
use App\Models\CalendarEvent;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
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
            ->recordTitleAttribute('title')
            ->recordUrl(fn ($record) => CalendarEventResource::getUrl('edit', ['record' => $record]))
            ->defaultSort('start_date', 'desc')

            ->columns([
                TextColumn::make('title')
                    ->label('Событие')
                    ->searchable()
                    ->sortable()
                    ->weight('medium')
                    ->description(fn (CalendarEvent $record): string => self::dateRange($record)),

                TextColumn::make('type')
                    ->label('Тип')
                    ->html()
                    ->formatStateUsing(fn (?string $state): HtmlString => self::typeBadge($state)),

                TextColumn::make('repeat_type')
                    ->label('Повторение')
                    ->formatStateUsing(fn (?string $state): string => CalendarEvent::repeatOptions()[$state] ?? '—')
                    ->badge()
                    ->color('gray')
                    ->sortable(),

                IconColumn::make('is_active')
                    ->label('Активно')
                    ->boolean()
                    ->trueIcon('heroicon-m-check-circle')
                    ->falseIcon('heroicon-m-x-circle')
                    ->trueColor('success')
                    ->falseColor('gray')
                    ->sortable(),

                TextColumn::make('priority')
                    ->label('Приоритет')
                    ->badge()
                    ->color(fn ($state): string => (int) $state > 0 ? 'warning' : 'gray')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('repeat_until')
                    ->label('Повторять до')
                    ->date('d.m.Y')
                    ->placeholder('—')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('created_at')
                    ->label('Создано')
                    ->dateTime('d.m.Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])

            ->filters([
                SelectFilter::make('type')
                    ->label('Тип')
                    ->options(CalendarEvent::typeOptions())
                    ->placeholder('Все'),

                SelectFilter::make('repeat_type')
                    ->label('Повторение')
                    ->options(CalendarEvent::repeatOptions())
                    ->placeholder('Все'),

                TernaryFilter::make('is_active')
                    ->label('Активность')
                    ->placeholder('Все')
                    ->trueLabel('Активные')
                    ->falseLabel('Неактивные')
                    ->default(true),
            ])

            ->actions([
                ActionGroup::make([
                    Action::make('activate')
                        ->label('Активировать')
                        ->icon('heroicon-m-check')
                        ->color('success')
                        ->visible(fn (CalendarEvent $record): bool => ! $record->is_active)
                        ->requiresConfirmation()
                        ->action(fn (CalendarEvent $record) => $record->update([
                            'is_active' => true,
                        ])),

                    Action::make('deactivate')
                        ->label('Скрыть')
                        ->icon('heroicon-m-eye-slash')
                        ->color('warning')
                        ->visible(fn (CalendarEvent $record): bool => $record->is_active)
                        ->requiresConfirmation()
                        ->action(fn (CalendarEvent $record) => $record->update([
                            'is_active' => false,
                        ])),

                    ViewAction::make()
                        ->label('Открыть'),

                    EditAction::make()
                        ->label('Редактировать'),

                    DeleteAction::make()
                        ->label('Удалить'),
                ]),
            ])

            ->bulkActions([]);
    }

    protected static function dateRange(CalendarEvent $record): string
    {
        $start = $record->start_date?->format('d.m.Y') ?? '—';
        $end = $record->end_date?->format('d.m.Y');

        return $end ? "{$start} — {$end}" : $start;
    }

    protected static function typeBadge(?string $state): HtmlString
    {
        $label = CalendarEvent::typeOptions()[$state] ?? (string) $state;

        $style = match ($state) {
            CalendarEvent::TYPE_WORKFLOW => 'background:#CFE8FF;color:#111111;',
            CalendarEvent::TYPE_FINANCE => 'background:#CBEED9;color:#111111;',
            CalendarEvent::TYPE_HOLIDAY => 'background:#F3B8B8;color:#111111;',
            CalendarEvent::TYPE_PEAK => 'background:#F3E69C;color:#111111;',
            CalendarEvent::TYPE_VACATION => 'background:#CDBEFF;color:#111111;',
            CalendarEvent::TYPE_STRIKE => 'background:#F4C9A8;color:#111111;',
            default => 'background:#E9E9E9;color:#111111;',
        };

        return new HtmlString(
            '<span style="' . $style . 'display:inline-flex;align-items:center;border-radius:9999px;padding:4px 10px;font-size:12px;font-weight:600;line-height:1.2;">'
            . e($label)
            . '</span>'
        );
    }
}