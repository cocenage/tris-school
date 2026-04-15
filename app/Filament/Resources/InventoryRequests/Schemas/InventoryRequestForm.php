<?php

namespace App\Filament\Resources\InventoryRequests\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class InventoryRequestForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Заявка')
                ->schema([
                    Select::make('user_id')
                        ->relationship('user', 'name')
                        ->label('Пользователь')
                        ->disabled(),

                    Select::make('status')
                        ->label('Статус')
                        ->options([
                            'pending' => 'На рассмотрении',
                            'approved' => 'Одобрено',
                            'partially_approved' => 'Частично одобрено',
                            'rejected' => 'Отклонено',
                        ])
                        ->required(),

                    Textarea::make('comment')
                        ->label('Комментарий пользователя')
                        ->disabled()
                        ->rows(3),

                    Textarea::make('admin_comment')
                        ->label('Комментарий администратора')
                        ->rows(3),

                    DateTimePicker::make('requested_at')
                        ->label('Запрошено')
                        ->seconds(false)
                        ->disabled(),

                    DateTimePicker::make('reviewed_at')
                        ->label('Рассмотрено')
                        ->seconds(false),
                ])
                ->columns(2),

            Section::make('Позиции')
                ->schema([
                    Repeater::make('items')
                        ->relationship()
                        ->label('')
                        ->schema([
                            TextInput::make('item_name')
                                ->label('Позиция')
                                ->disabled(),

                            TextInput::make('requested_qty')
                                ->label('Запрошено')
                                ->numeric()
                                ->disabled(),

                            TextInput::make('approved_qty')
                                ->label('Одобрено')
                                ->numeric()
                                ->minValue(0)
                                ->required(),

                            Select::make('status')
                                ->label('Статус')
                                ->options([
                                    'pending' => 'На рассмотрении',
                                    'approved' => 'Одобрено',
                                    'rejected' => 'Отклонено',
                                ])
                                ->required(),

                            Textarea::make('admin_comment')
                                ->label('Комментарий')
                                ->rows(2)
                                ->columnSpanFull(),
                        ])
                        ->columns(3)
                        ->addable(false)
                        ->deletable(false)
                        ->reorderable(false),
                ]),
        ]);
    }
}