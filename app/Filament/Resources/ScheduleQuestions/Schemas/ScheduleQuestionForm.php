<?php

namespace App\Filament\Resources\ScheduleQuestions\Schemas;

use App\Models\ScheduleQuestion;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ScheduleQuestionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Вопрос по графику')
                ->description('Основная информация по обращению сотрудника')
                ->schema([
                    Placeholder::make('user_name')
                        ->label('Сотрудник')
                        ->content(fn ($record) => $record?->user?->name ?? '—'),

                    Placeholder::make('type')
                        ->label('Тип вопроса')
                        ->content(fn ($record) => $record?->type ?? '—'),

                    Select::make('status')
                        ->label('Статус')
                        ->options(function (?ScheduleQuestion $record): array {
                            if (! $record?->usesDecisionWorkflow()) {
                                return ['pending' => 'На рассмотрении', 'reviewed' => 'Рассмотрено', 'closed' => 'Закрыто'];
                            }
                            if (! in_array($record->status, ['pending', 'in_progress'], true)) {
                                return [$record->status => ScheduleQuestion::STATUS_LABELS[$record->status] ?? $record->status];
                            }
                            return array_intersect_key(ScheduleQuestion::STATUS_LABELS, array_flip(
                                $record->status === 'pending' ? ['pending', ...ScheduleQuestion::DECISION_STATUSES] : ScheduleQuestion::DECISION_STATUSES
                            ));
                        })
                        ->live()
                        ->required()
                        ->native(false),

                    Hidden::make('decision_version')->dehydrated(fn (?ScheduleQuestion $record): bool => $record?->usesDecisionWorkflow() ?? false),

                    Placeholder::make('created_at')
                        ->label('Создано')
                        ->content(fn ($record) => $record?->created_at?->format('d.m.Y H:i') ?? '—'),
                ])
                ->columns(4)
                ->columnSpanFull(),

            Section::make('Комментарии')
                ->schema([
                    Textarea::make('comment')
                        ->label('Комментарий сотрудника')
                        ->disabled()
                        ->dehydrated(false)
                        ->rows(6)
                        ->placeholder('—')
                        ->columnSpanFull(),

                    Textarea::make('admin_comment')
                        ->label('Комментарий руководителя')
                        ->required(fn (?ScheduleQuestion $record, $get): bool => ($record?->usesDecisionWorkflow() ?? false) && in_array($get('status'), ScheduleQuestion::DECISION_STATUSES, true))
                        ->maxLength(1800)
                        ->rows(4)
                        ->placeholder('Например: вопрос проверен, ответ передан сотруднику')
                        ->columnSpanFull(),
                ])
                ->columnSpanFull(),
        ]);
    }
}