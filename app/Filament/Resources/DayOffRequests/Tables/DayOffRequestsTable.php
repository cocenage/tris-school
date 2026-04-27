<?php

namespace App\Filament\Resources\DayOffRequests\Tables;

use App\Filament\Resources\DayOffRequests\DayOffRequestResource;
use App\Models\DayOffRequest;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Filament\Forms\Components\DatePicker;

class DayOffRequestsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['user', 'days']))
            ->recordTitleAttribute('user_name')
            ->recordUrl(fn ($record) => DayOffRequestResource::getUrl('edit', ['record' => $record]))
            ->defaultSort('created_at', 'desc')

            ->columns([
                TextColumn::make('user.name')
                    ->label('Сотрудник')
                    ->searchable()
                    ->sortable()
                    ->weight('medium')
                    ->description(fn (DayOffRequest $record): string => $record->created_at?->format('d.m.Y H:i') ?? '—'),

                TextColumn::make('dates')
                    ->label('Даты')
                    ->state(function (DayOffRequest $record): string {
                        if ($record->days->isEmpty()) {
                            return '—';
                        }

                        return $record->days
                            ->sortBy('date')
                            ->map(fn ($day): string => Carbon::parse($day->date)->format('d.m.Y'))
                            ->implode(', ');
                    })
                    ->wrap(),

                TextColumn::make('days_count')
                    ->label('Дней')
                    ->state(fn (DayOffRequest $record): int => $record->days->count())
                    ->badge()
                    ->color('gray'),

                TextColumn::make('status')
                    ->label('Статус')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => self::statusLabel($state))
                    ->color(fn (?string $state): string => self::statusColor($state))
                    ->sortable(),

                TextColumn::make('reason')
                    ->label('Причина')
                    ->limit(70)
                    ->wrap()
                    ->placeholder('—')
                    ->searchable(),

                TextColumn::make('admin_comment')
                    ->label('Комментарий администратора')
                    ->limit(50)
                    ->placeholder('—')
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: true),

            ])

            ->filters([
                SelectFilter::make('status')
                    ->label('Статус заявки')
                    ->options([
                        'pending' => 'На рассмотрении',
                        'approved' => 'Одобрено',
                        'rejected' => 'Отклонено',
                        'partially_approved' => 'Частично одобрено',
                    ])
                    ->placeholder('Все'),

                SelectFilter::make('user_id')
                    ->label('Сотрудник')
                    ->relationship('user', 'name')
                    ->searchable()
                    ->preload(),

                Filter::make('day_date')
                    ->label('Дата выходного')
                    ->form([
                        DatePicker::make('day_from')
                            ->label('Дата от')
                            ->native(false),

                        DatePicker::make('day_until')
                            ->label('Дата до')
                            ->native(false),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query->when(
                            ($data['day_from'] ?? null) || ($data['day_until'] ?? null),
                            function (Builder $query) use ($data): Builder {
                                return $query->whereHas('days', function (Builder $daysQuery) use ($data): void {
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

            ->actions([
                ActionGroup::make([
                    Action::make('approve')
                        ->label('Одобрить всё')
                        ->icon('heroicon-m-check')
                        ->color('success')
                        ->visible(fn (DayOffRequest $record): bool => $record->status !== 'approved')
                        ->requiresConfirmation()
                        ->action(function (DayOffRequest $record): void {
                            $record->update([
                                'status' => 'approved',
                            ]);

                            $record->days()->update([
                                'status' => 'approved',
                            ]);
                        }),

                    Action::make('reject')
                        ->label('Отклонить всё')
                        ->icon('heroicon-m-x-mark')
                        ->color('danger')
                        ->visible(fn (DayOffRequest $record): bool => $record->status !== 'rejected')
                        ->requiresConfirmation()
                        ->schema([
                            Textarea::make('admin_comment')
                                ->label('Комментарий администратора')
                                ->rows(3)
                                ->default(fn (DayOffRequest $record) => $record->admin_comment),
                        ])
                        ->action(function (DayOffRequest $record, array $data): void {
                            $record->update([
                                'status' => 'rejected',
                                'admin_comment' => filled($data['admin_comment'] ?? null)
                                    ? trim((string) $data['admin_comment'])
                                    : $record->admin_comment,
                            ]);

                            $record->days()->update([
                                'status' => 'rejected',
                            ]);
                        }),

                    Action::make('set_admin_comment')
                        ->label('Комментарий администратора')
                        ->icon('heroicon-m-chat-bubble-left-right')
                        ->color('gray')
                        ->schema([
                            Textarea::make('admin_comment')
                                ->label('Комментарий администратора')
                                ->rows(4)
                                ->default(fn (DayOffRequest $record) => $record->admin_comment),
                        ])
                        ->action(function (DayOffRequest $record, array $data): void {
                            $record->update([
                                'admin_comment' => filled($data['admin_comment'] ?? null)
                                    ? trim((string) $data['admin_comment'])
                                    : null,
                            ]);
                        }),

                    ViewAction::make()
                        ->label('Открыть'),

                    EditAction::make()
                        ->label('Обработать'),
                ]),
            ])

            ->bulkActions([]);
    }

    protected static function statusLabel(?string $status): string
    {
        return match ($status) {
            'approved' => 'Одобрено',
            'rejected' => 'Отклонено',
            'partially_approved' => 'Частично одобрено',
            default => 'На рассмотрении',
        };
    }

    protected static function statusColor(?string $status): string
    {
        return match ($status) {
            'approved' => 'success',
            'rejected' => 'danger',
            'partially_approved' => 'warning',
            default => 'info',
        };
    }
}