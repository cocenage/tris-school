<?php

namespace App\Filament\Resources\InventoryRequests\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class InventoryRequestInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Заявка')
                    ->schema([
                        TextEntry::make('user.name')
                            ->label('Сотрудник'),

                        TextEntry::make('status')
                            ->label('Статус')
                            ->badge()
                            ->formatStateUsing(fn (string $state): string => match ($state) {
                                'issued' => 'Выдано',
                                'partially_issued' => 'Выдано частично',
                                'cancelled' => 'Не выдано',
                                default => 'На рассмотрении',
                            })
                            ->color(fn (string $state): string => match ($state) {
                                'issued' => 'success',
                                'partially_issued' => 'warning',
                                'cancelled' => 'danger',
                                default => 'info',
                            }),

                        TextEntry::make('comment')
                            ->label('Комментарий пользователя')
                            ->placeholder('—')
                            ->columnSpanFull(),

                        TextEntry::make('admin_comment')
                            ->label('Комментарий администратора')
                            ->placeholder('—')
                            ->columnSpanFull(),

                        TextEntry::make('created_at')
                            ->label('Создано')
                            ->dateTime('d.m.Y H:i'),

                        TextEntry::make('processed_at')
                            ->label('Обработано')
                            ->dateTime('d.m.Y H:i')
                            ->placeholder('—'),
                    ])
                    ->columns(2),
            ]);
    }
}