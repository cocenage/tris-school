<?php

namespace App\Filament\Resources\VacationRequests\Tables;

use App\Models\VacationRequest;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class VacationRequestsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['user', 'days']))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('user.name')
                    ->label('Сотрудник')
                    ->searchable()
                    ->toggleable()
                    ->sortable(),

                TextColumn::make('dates')
                    ->label('Даты')
                    ->state(function (VacationRequest $record): string {
                        return $record->days
                            ->sortBy('date')
                            ->map(fn ($day) => \Carbon\Carbon::parse($day->date)->format('d.m.Y'))
                            ->implode(', ');
                    })
                    ->toggleable()
                    ->wrap(),

                TextColumn::make('reason')
                    ->label('Причина')
                    ->limit(60)
                    ->wrap()
                    ->toggleable()
                    ->searchable(),

                TextColumn::make('admin_comment')
                    ->label('Комментарий администратора')
                    ->limit(60)
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->wrap(),

                TextColumn::make('status')
                    ->label('Статус')
                    ->badge()
                    ->toggleable()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'approved' => 'Одобрено',
                        'rejected' => 'Отклонено',
                        'partially_approved' => 'Частично одобрено',
                        default => 'На рассмотрении',
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'approved' => 'success',
                        'rejected' => 'danger',
                        'partially_approved' => 'warning',
                        default => 'info',
                    })
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label('Создано')
                    ->toggleable()
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Статус заявки')
                    ->options([
                        'pending' => 'На рассмотрении',
                        'approved' => 'Одобрено',
                        'rejected' => 'Отклонено',
                        'partially_approved' => 'Частично одобрено',
                    ]),

                SelectFilter::make('user_id')
                    ->label('Сотрудник')
                    ->relationship('user', 'name')
                    ->searchable()
                    ->preload(),

                Filter::make('day_date')
                    ->label('Дата отпуска')
                    ->form([
                        DatePicker::make('day_from')
                            ->label('Дата от'),
                        DatePicker::make('day_until')
                            ->label('Дата до'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                ($data['day_from'] ?? null) || ($data['day_until'] ?? null),
                                function (Builder $query) use ($data): Builder {
                                    return $query->whereHas('days', function (Builder $daysQuery) use ($data) {
                                        $daysQuery
                                            ->when(
                                                $data['day_from'] ?? null,
                                                fn (Builder $q, $date): Builder => $q->whereDate('date', '>=', $date),
                                            )
                                            ->when(
                                                $data['day_until'] ?? null,
                                                fn (Builder $q, $date): Builder => $q->whereDate('date', '<=', $date),
                                            );
                                    });
                                }
                            );
                    }),
            ])
            ->recordActions([
                ActionGroup::make([
                    Action::make('approve')
                        ->label('Одобрить')
                        ->icon('heroicon-o-check')
                        ->color('success')
                        ->requiresConfirmation()
                        ->visible(fn (VacationRequest $record) => $record->status !== 'approved')
                        ->action(function (VacationRequest $record): void {
                            foreach ($record->days as $day) {
                                $day->update([
                                    'status' => 'approved',
                                    'admin_comment' => null,
                                    'reviewed_at' => now(),
                                    'reviewed_by' => auth()->id(),
                                ]);
                            }

                            $record->resetNotification();
                            $record->syncStatusAndNotify();
                        }),

                    Action::make('reject')
                        ->label('Отклонить')
                        ->icon('heroicon-o-x-mark')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->visible(fn (VacationRequest $record) => $record->status !== 'rejected')
                        ->action(function (VacationRequest $record): void {
                            foreach ($record->days as $day) {
                                $day->update([
                                    'status' => 'rejected',
                                    'reviewed_at' => now(),
                                    'reviewed_by' => auth()->id(),
                                ]);
                            }

                            $record->resetNotification();
                            $record->syncStatusAndNotify();
                        }),

                    EditAction::make(),
                ]),
            ])
            ->toolbarActions([
                //
            ]);
    }
}