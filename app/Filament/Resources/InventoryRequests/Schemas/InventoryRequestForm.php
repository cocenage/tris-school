<?php

namespace App\Filament\Resources\InventoryRequests\Schemas;

use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

class InventoryRequestForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Заявка')
                    ->description('Основная информация по заявке на инвентарь')
                    ->schema([
                        Placeholder::make('user_name')
                            ->label('Сотрудник')
                            ->content(fn ($record) => $record?->user?->name ?? '—'),

                        Placeholder::make('status')
                            ->label('Статус заявки')
                            ->content(function ($record) {
                                $label = match ($record?->status) {
                                    'issued' => 'Выдано',
                                    'partially_issued' => 'Выдано частично',
                                    'cancelled' => 'Не выдано',
                                    default => 'На рассмотрении',
                                };

                                return new HtmlString('<strong>' . e($label) . '</strong>');
                            }),

                        Placeholder::make('created_at')
                            ->label('Создано')
                            ->content(fn ($record) => $record?->created_at?->format('d.m.Y H:i') ?? '—'),

                        Placeholder::make('processed_at')
                            ->label('Обработано')
                            ->content(fn ($record) => $record?->processed_at?->format('d.m.Y H:i') ?? '—'),
                    ])
                    ->columns(4)
                    ->columnSpanFull(),

                Section::make('Комментарии')
                    ->description('Комментарий пользователя нельзя редактировать. Комментарий администратора виден в обработке')
                    ->schema([
                        Textarea::make('comment')
                            ->label('Комментарий пользователя')
                            ->rows(4)
                            ->disabled()
                            ->dehydrated(false)
                            ->placeholder('—')
                            ->columnSpanFull(),

                        Textarea::make('admin_comment')
                            ->label('Комментарий администратора')
                            ->rows(4)
                            ->placeholder('Например: часть товаров выдана, остальное позже')
                            ->columnSpanFull(),
                    ])
                    ->columnSpanFull(),
            ]);
    }
}