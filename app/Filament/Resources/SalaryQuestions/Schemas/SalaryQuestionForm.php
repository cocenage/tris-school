<?php

namespace App\Filament\Resources\SalaryQuestions\Schemas;

use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Schema;

class SalaryQuestionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Placeholder::make('user_name')
                ->label('Сотрудник')
                ->content(fn ($record) => $record?->user?->name ?? '—'),

            Select::make('status')
                ->label('Статус')
                ->options([
                    'pending' => 'На рассмотрении',
                    'reviewed' => 'Рассмотрено',
                    'closed' => 'Закрыто',
                ])
                ->required(),

            Placeholder::make('type')
                ->label('Тип вопроса')
                ->content(fn ($record) => $record?->type ?? '—'),

            Textarea::make('comment')
                ->label('Комментарий')
                ->disabled()
                ->dehydrated(false)
                ->rows(6)
                ->columnSpanFull(),

            Textarea::make('admin_comment')
                ->label('Комментарий администратора')
                ->rows(4)
                ->columnSpanFull(),
        ]);
    }
}