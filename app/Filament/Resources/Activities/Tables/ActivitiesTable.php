<?php

namespace App\Filament\Resources\Activities\Tables;

use App\Models\User;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Spatie\Activitylog\Models\Activity;

class ActivitiesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')
                    ->label('Дата')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),

                TextColumn::make('causer.name')
                    ->label('Пользователь')
                    ->placeholder('Система')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('event')
                    ->label('Событие')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => self::eventLabel($state))
                    ->color(fn (?string $state): string => self::eventColor($state))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('description')
                    ->label('Описание')
                    ->searchable()
                    ->limit(70)
                    ->wrap(),

                TextColumn::make('subject_type')
                    ->label('Раздел')
                    ->formatStateUsing(fn (?string $state): string => $state ? class_basename($state) : '—')
                    ->badge()
                    ->color('gray')
                    ->toggleable(),

                TextColumn::make('subject_id')
                    ->label('ID записи')
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('properties')
                    ->label('Детали')
                    ->formatStateUsing(function (Activity $record): string {
                        if ($record->properties->isEmpty()) {
                            return '—';
                        }

                        return $record->properties
                            ->map(fn ($value, $key) => "{$key}: " . self::stringify($value))
                            ->implode(' | ');
                    })
                    ->limit(120)
                    ->wrap()
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('event')
                    ->label('Событие')
                    ->options(fn (): array => Activity::query()
                        ->whereNotNull('event')
                        ->distinct()
                        ->orderBy('event')
                        ->pluck('event', 'event')
                        ->mapWithKeys(fn ($event) => [$event => self::eventLabel($event)])
                        ->toArray()),

                SelectFilter::make('causer_id')
                    ->label('Пользователь')
                    ->options(fn (): array => User::query()
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->toArray())
                    ->query(function (Builder $query, array $data): Builder {
                        if (blank($data['value'] ?? null)) {
                            return $query;
                        }

                        return $query
                            ->where('causer_type', User::class)
                            ->where('causer_id', $data['value']);
                    }),

                Filter::make('today')
                    ->label('Сегодня')
                    ->query(fn (Builder $query): Builder => $query->whereDate('created_at', today())),

                Filter::make('week')
                    ->label('За 7 дней')
                    ->query(fn (Builder $query): Builder => $query->where('created_at', '>=', now()->subDays(7))),

                Filter::make('requests')
                    ->label('Только заявки')
                    ->query(fn (Builder $query): Builder => $query->whereIn('event', [
                        'salary_question_created',
                        'day_off_request_created',
                        'vacation_request_created',
                        'inventory_request_created',
                    ])),
            ])
            ->recordActions([
    \Filament\Actions\ViewAction::make(),
]);
    }

    protected static function eventLabel(?string $event): string
    {
        return match ($event) {
            'salary_question_created' => 'Вопрос по зарплате',
            'day_off_request_created' => 'Заявка на выходной',
            'vacation_request_created' => 'Заявка на отпуск',
            'inventory_request_created' => 'Заявка на инвентарь',

            'control_started' => 'Контроль открыт',
            'control_completed' => 'Контроль пройден',

            'profile_opened' => 'Профиль открыт',
            'calendar_opened' => 'Календарь открыт',

            default => $event ?: 'Без события',
        };
    }

    protected static function eventColor(?string $event): string
    {
        return match ($event) {
            'salary_question_created',
            'day_off_request_created',
            'vacation_request_created',
            'inventory_request_created' => 'success',

            'control_started',
            'control_completed' => 'info',

            'profile_opened',
            'calendar_opened' => 'gray',

            default => 'warning',
        };
    }

    protected static function stringify(mixed $value): string
    {
        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE);
        }

        if (is_bool($value)) {
            return $value ? 'да' : 'нет';
        }

        if ($value === null) {
            return '—';
        }

        return (string) $value;
    }
}