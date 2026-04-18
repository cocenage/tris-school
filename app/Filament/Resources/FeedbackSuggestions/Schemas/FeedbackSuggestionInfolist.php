<?php

namespace App\Filament\Resources\FeedbackSuggestions\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class FeedbackSuggestionInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Заявка')
                ->schema([
                    TextEntry::make('user.name')->label('Сотрудник'),
                    TextEntry::make('type')->label('Тип вопроса'),
                    TextEntry::make('status')
                        ->label('Статус')
                        ->badge()
                        ->formatStateUsing(fn (string $state) => match ($state) {
                            'reviewed' => 'Рассмотрено',
                            'closed' => 'Закрыто',
                            default => 'На рассмотрении',
                        }),
                    TextEntry::make('comment')->label('Комментарий')->columnSpanFull(),
                    TextEntry::make('admin_comment')->label('Комментарий администратора')->placeholder('—')->columnSpanFull(),
                    TextEntry::make('created_at')->label('Создано')->dateTime('d.m.Y H:i'),
                ])
                ->columns(2),
        ]);
    }
}