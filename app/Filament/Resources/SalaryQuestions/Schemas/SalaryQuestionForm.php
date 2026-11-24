<?php

namespace App\Filament\Resources\SalaryQuestions\Schemas;

use App\Models\SalaryQuestion;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class SalaryQuestionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Вопрос по зарплате')
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
                        ->options(function (?SalaryQuestion $record): array {
                            if (! $record?->usesDecisionWorkflow()) {
                                return ['pending' => 'На рассмотрении', 'reviewed' => 'Рассмотрено', 'closed' => 'Закрыто'];
                            }
                            if (! in_array($record->status, ['pending', 'in_progress'], true)) {
                                return [$record->status => SalaryQuestion::STATUS_LABELS[$record->status] ?? $record->status];
                            }
                            return array_intersect_key(SalaryQuestion::STATUS_LABELS, array_flip(
                                $record->status === 'pending' ? ['pending', ...SalaryQuestion::DECISION_STATUSES] : SalaryQuestion::DECISION_STATUSES
                            ));
                        })
                        ->live()
                        ->required()
                        ->native(false),

                    Hidden::make('decision_version')->dehydrated(fn (?SalaryQuestion $record): bool => $record?->usesDecisionWorkflow() ?? false),

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
                        ->required(fn (?SalaryQuestion $record, $get): bool => ($record?->usesDecisionWorkflow() ?? false) && in_array($get('status'), SalaryQuestion::DECISION_STATUSES, true))
                        ->maxLength(1800)
                        ->rows(4)
                        ->placeholder('Например: вопрос проверен, ответ передан сотруднику')
                        ->columnSpanFull(),
                ])
                ->columnSpanFull(),
        ]);
    }
}