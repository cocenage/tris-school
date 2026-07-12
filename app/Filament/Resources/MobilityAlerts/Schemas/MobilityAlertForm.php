<?php

namespace App\Filament\Resources\MobilityAlerts\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Schema;

class MobilityAlertForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('source')
                    ->label('Источник')
                    ->options([
                        'atm' => 'ATM',
                        'telegram' => 'Telegram',
                        'trenord' => 'Trenord',
                        'manual' => 'Вручную',
                    ])
                    ->searchable()
                    ->required(),

                TextInput::make('title')
                    ->label('Заголовок')
                    ->required()
                    ->maxLength(255)
                    ->columnSpanFull(),

                Textarea::make('description')
                    ->label('Описание')
                    ->rows(6)
                    ->columnSpanFull(),

                TextInput::make('url')
                    ->label('Ссылка')
                    ->url()
                    ->maxLength(2048)
                    ->columnSpanFull(),

                Select::make('type')
                    ->label('Тип')
                    ->options([
                        'strike' => 'Забастовка',
                        'transport' => 'Транспорт',
                        'metro' => 'Метро',
                        'train' => 'Поезда',
                        'traffic' => 'Трафик',
                        'event' => 'Мероприятие',
                        'roadworks' => 'Работы / перекрытия',
                        'other' => 'Другое',
                    ])
                    ->searchable()
                    ->default('transport'),

                Select::make('risk')
                    ->label('Риск')
                    ->options([
                        'critical' => 'Критичный',
                        'high' => 'Высокий',
                        'medium' => 'Средний',
                        'low' => 'Низкий',
                    ])
                    ->required()
                    ->default('medium'),

                TextInput::make('district')
                    ->label('Район / линия')
                    ->maxLength(255),

                DatePicker::make('starts_at')
                    ->label('Начало'),

                DatePicker::make('ends_at')
                    ->label('Конец'),

                DateTimePicker::make('sent_at')
                    ->label('Отправлено')
                    ->disabled(),

                TextInput::make('external_hash')
                    ->label('Хэш источника')
                    ->required()
                    ->maxLength(255)
                    ->columnSpanFull(),
            ]);
    }
}