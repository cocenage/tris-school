<?php

namespace App\Filament\Resources\ControlResponses\Tables;

use App\Filament\Resources\ControlResponses\ControlResponseResource;
use App\Models\ControlResponse;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Gate;

class ControlResponsesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->persistFiltersInSession()
            ->persistSearchInSession()
            ->columns([
                TextColumn::make('cleaner.name')
                    ->label('Сотрудник')
                    ->weight('medium')
                    ->wrap()
                    ->description(fn (ControlResponse $record): string => implode(' · ', array_filter([
                        $record->apartment?->name,
                        $record->inspection_date ? \Carbon\Carbon::parse($record->inspection_date)->format('d.m.Y') : null,
                        'Ошибок: ' . $record->errors_count,
                    ])))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('result_zone_label')
                    ->label('Цвет')
                    ->badge()
                    ->color(fn (ControlResponse $record): string => $record->result_zone_color),

                TextColumn::make('errors_count')->visibleFrom('md')
                    ->label('Ошибки')
                    ->sortable()
                    ->alignCenter()
                    ->badge()
                    ->color(fn (ControlResponse $record): string => $record->errors_count > 0 ? 'danger' : 'success'),

                TextColumn::make('penalty_points')->visibleFrom('md')
                    ->label('Штраф')
                    ->sortable()
                    ->alignCenter()
                    ->badge()
                    ->color(fn (ControlResponse $record): string => $record->penalty_points > 0 ? 'danger' : 'success'),

                TextColumn::make('result_zone_reason')->visibleFrom('md')
                    ->label('Причина')
                    ->wrap()
                    ->toggleable(),

                TextColumn::make('inspection_date')->visibleFrom('md')
                    ->label('Дата контроля')
                    ->date('d.m.Y')
                    ->sortable(),

                TextColumn::make('apartment.name')->visibleFrom('md')
                    ->label('Квартира')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('supervisor.name')->visibleFrom('md')
                    ->label('Кто проверил')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('control.name')->visibleFrom('md')
                    ->label('Форма')
                    ->searchable()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('sent_at')->visibleFrom('md')
                    ->label('Отправлено')
                    ->dateTime('d.m.Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('result_zone')
                    ->label('Цвет')
                    ->options([
                        'green' => 'Зелёная зона',
                        'yellow' => 'Жёлтая зона',
                        'red' => 'Красная зона',
                    ]),

                SelectFilter::make('cleaner_id')
                    ->label('Клинер')
                    ->relationship('cleaner', 'name')
                    ->searchable()
                    ->preload(),

                SelectFilter::make('supervisor_id')
                    ->label('Супервайзер')
                    ->relationship('supervisor', 'name')
                    ->searchable()
                    ->preload(),

                SelectFilter::make('apartment_id')
                    ->label('Квартира')
                    ->relationship('apartment', 'name')
                    ->searchable()
                    ->preload(),
            ])
            ->defaultSort('sent_at', 'desc')
            ->recordUrl(fn (ControlResponse $record) => ControlResponseResource::getUrl('view', ['record' => $record]))
            ->actions([
                ViewAction::make(),
            ])
            ->toolbarActions([
                DeleteBulkAction::make()
                    ->label('Удалить выбранные')
                    ->modalHeading('Удалить выбранные проверки?')
                    ->modalDescription(fn (DeleteBulkAction $action): string => 'Выбрано проверок: '.$action->getSelectedRecords()->count().'. Удаление нельзя отменить.')
                    ->authorize(fn (): bool => auth()->user()?->isAdmin() === true && Gate::allows('deleteAny', ControlResponse::class))
                    ->authorizeIndividualRecords('delete'),
            ]);
    }
}
