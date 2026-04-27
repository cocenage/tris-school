<?php

namespace App\Filament\Resources\SalaryQuestions\Schemas;

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
                        ->options([
                            'pending' => 'На рассмотрении',
                            'reviewed' => 'Рассмотрено',
                            'closed' => 'Закрыто',
                        ])
                        ->required()
                        ->native(false),

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
                        ->label('Комментарий администратора')
                        ->rows(4)
                        ->placeholder('Например: вопрос проверен, ответ передан сотруднику')
                        ->columnSpanFull(),
                ])
                ->columnSpanFull(),
        ]);
    }
}