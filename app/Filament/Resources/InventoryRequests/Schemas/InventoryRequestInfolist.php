<?php

namespace App\Filament\Resources\InventoryRequests\Schemas;

use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class InventoryRequestInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Заявка')
                ->schema([
                    TextEntry::make('id')
                        ->label('#'),

                    TextEntry::make('user.name')
                        ->label('Пользователь'),

                    TextEntry::make('status')
                        ->label('Статус')
                        ->formatStateUsing(fn (string $state) => match ($state) {
                            'approved' => 'Одобрено',
                            'partially_approved' => 'Частично одобрено',
                            'rejected' => 'Отклонено',
                            default => 'На рассмотрении',
                        }),

                    TextEntry::make('comment')
                        ->label('Комментарий пользователя'),

                    TextEntry::make('admin_comment')
                        ->label('Комментарий администратора'),

                    TextEntry::make('requested_at')
                        ->label('Запрошено')
                        ->dateTime('d.m.Y H:i'),

                    TextEntry::make('reviewed_at')
                        ->label('Рассмотрено')
                        ->dateTime('d.m.Y H:i'),
                ])
                ->columns(2),

            Section::make('Позиции')
                ->schema([
                    RepeatableEntry::make('items')
                        ->label('')
                        ->schema([
                            TextEntry::make('item_name')
                                ->label('Позиция'),

                            TextEntry::make('requested_qty')
                                ->label('Запрошено'),

                            TextEntry::make('approved_qty')
                                ->label('Одобрено'),

                            TextEntry::make('status')
                                ->label('Статус')
                                ->formatStateUsing(fn (string $state) => match ($state) {
                                    'approved' => 'Одобрено',
                                    'rejected' => 'Отклонено',
                                    default => 'На рассмотрении',
                                }),

                            TextEntry::make('admin_comment')
                                ->label('Комментарий'),
                        ])
                        ->columns(3),
                ]),
        ]);
    }
}