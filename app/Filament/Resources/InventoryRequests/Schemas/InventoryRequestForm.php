<?php

namespace App\Filament\Resources\InventoryRequests\Schemas;

use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Schema;

class InventoryRequestForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Placeholder::make('user_name')
                    ->label('Сотрудник')
                    ->content(fn ($record) => $record?->user?->name ?? '—'),

                Placeholder::make('status')
                    ->label('Статус заявки')
                    ->content(fn ($record) => match ($record?->status) {
                        'issued' => 'Выдано',
                        'partially_issued' => 'Выдано частично',
                        'cancelled' => 'Не выдано',
                        default => 'На рассмотрении',
                    }),

                Textarea::make('comment')
                    ->label('Комментарий пользователя')
                    ->rows(4)
                    ->disabled()
                    ->dehydrated(false)
                    ->columnSpanFull(),

                Textarea::make('admin_comment')
                    ->label('Комментарий администратора')
                    ->rows(4)
                    ->columnSpanFull(),
            ]);
    }
}