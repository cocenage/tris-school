<?php

namespace App\Filament\Resources\RewardPrograms\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class RewardProgramForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Название')
                    ->required()
                    ->maxLength(255),

                Textarea::make('description')
                    ->label('Описание')
                    ->rows(4)
                    ->columnSpanFull(),

                DatePicker::make('starts_at')
                    ->label('Начало'),

                DatePicker::make('ends_at')
                    ->label('Конец'),

                KeyValue::make('targets')
                    ->label('Цели')
                    ->keyLabel('Название')
                    ->valueLabel('Баллы')
                    ->default([
                        'Сотрудник +1 гость' => 230,
                        'Сотрудник +2 гостя' => 320,
                        'Family Recovery Stay' => 400,
                    ])
                    ->columnSpanFull(),

                Toggle::make('is_active')
                    ->label('Активна')
                    ->default(true),
            ]);
    }
}