<?php

namespace App\Filament\Resources\Apartments\Schemas;

use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ApartmentForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make([
                'default' => 1,
                'lg' => 3,
            ])->schema([
                Section::make('Основная информация')
                    ->columnSpan([
                        'default' => 1,
                        'lg' => 2,
                    ])
                    ->schema([
                        Grid::make([
                            'default' => 1,
                            'md' => 2,
                        ])->schema([
                            TextInput::make('name')
                                ->label('Название')
                                ->required()
                                ->maxLength(255),

                            TextInput::make('code')
                                ->label('Код объекта')
                                ->maxLength(100)
                                ->helperText('Например: SAV-01 или APT-12'),
                        ]),

                        Textarea::make('address')
                            ->label('Адрес')
                            ->rows(3)
                            ->columnSpanFull(),

                        FileUpload::make('image')
                            ->label('Фото объекта')
                            ->image()
                            ->imageEditor()
                            ->directory('apartments')
                            ->visibility('public')
                            ->maxSize(10240)
                            ->columnSpanFull(),

                        Textarea::make('notes')
                            ->label('Служебная заметка')
                            ->rows(4)
                            ->columnSpanFull(),
                    ]),

                Section::make('Настройки')
                    ->columnSpan([
                        'default' => 1,
                        'lg' => 1,
                    ])
                    ->schema([
                        Toggle::make('is_active')
                            ->label('Активна')
                            ->default(true),

                        TextInput::make('sort_order')
                            ->label('Порядок сортировки')
                            ->numeric()
                            ->default(0)
                            ->minValue(0),

                        KeyValue::make('meta')
                            ->label('Meta')
                            ->keyLabel('Ключ')
                            ->valueLabel('Значение')
                            ->columnSpanFull(),
                    ]),
            ]),
        ]);
    }
}