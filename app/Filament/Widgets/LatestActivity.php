<?php

namespace App\Filament\Widgets;

use App\Models\User;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Spatie\Activitylog\Models\Activity;

class LatestActivity extends TableWidget
{
    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'Последние действия';

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Activity::query()
                    ->latest()
                    ->limit(10)
            )
            ->paginated(false)
            ->columns([
                Tables\Columns\TextColumn::make('event')
                    ->label('Событие')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => self::eventLabel($state))
                    ->color(fn (?string $state): string => self::eventColor($state)),

                Tables\Columns\TextColumn::make('causer.name')
                    ->label('Пользователь')
                    ->placeholder('Система')
                    ->searchable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Когда')
                    ->since()
                    ->sortable(),
            ]);
    }

    protected static function eventLabel(?string $event): string
    {
        return match ($event) {
            'salary_question_created' => 'Вопрос по зарплате',
            'feedback_suggestion_created' => 'Обратная связь',
            'schedule_question_created' => 'Вопрос по графику',
            'day_off_request_created' => 'Заявка на выходной',
            'vacation_request_created' => 'Заявка на отпуск',
            'inventory_request_created' => 'Заявка на инвентарь',
            'control_completed' => 'Контроль пройден',
            'created' => 'Создание',
            'updated' => 'Изменение',
            'deleted' => 'Удаление',
            default => $event ?: 'Без события',
        };
    }

    protected static function eventColor(?string $event): string
    {
        return match ($event) {
            'day_off_request_created',
            'vacation_request_created',
            'inventory_request_created',
            'salary_question_created',
            'feedback_suggestion_created',
            'schedule_question_created' => 'warning',

            'control_completed' => 'success',

            'created' => 'primary',
            'updated' => 'info',
            'deleted' => 'danger',

            default => 'gray',
        };
    }
}