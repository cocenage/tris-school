<?php

namespace App\Filament\Resources\FeedbackSuggestions\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class FeedbackSuggestionInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(12)
                ->schema([
                    Section::make('Обращение')
                        ->schema([
                            TextEntry::make('user.name')
                                ->label('Сотрудник')
                                ->weight('bold'),

                            TextEntry::make('type')
                                ->label('Тип')
                                ->badge()
                                ->color('gray'),

                            TextEntry::make('status')
                                ->label('Статус')
                                ->badge()
                                ->formatStateUsing(fn (?string $state): string => self::statusLabel($state))
                                ->color(fn (?string $state): string => self::statusColor($state)),

                            TextEntry::make('created_at')
                                ->label('Создано')
                                ->dateTime('d.m.Y H:i'),
                        ])
                        ->columns(2)
                        ->columnSpan(12),

                    Section::make('Комментарии')
                        ->schema([
                            TextEntry::make('comment')
                                ->label('Сообщение сотрудника')
                                ->placeholder('—')
                                ->columnSpanFull(),

                            TextEntry::make('admin_comment')
                                ->label('Комментарий администратора')
                                ->placeholder('—')
                                ->columnSpanFull(),
                        ])
                        ->columnSpan(12),
                ]),
        ]);
    }

    protected static function statusLabel(?string $status): string
    {
        return match ($status) {
            'reviewed' => 'Рассмотрено',
            'closed' => 'Закрыто',
            default => 'На рассмотрении',
        };
    }

    protected static function statusColor(?string $status): string
    {
        return match ($status) {
            'reviewed' => 'info',
            'closed' => 'success',
            default => 'warning',
        };
    }
}