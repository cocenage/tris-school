<?php

namespace App\Filament\Resources\CalendarEvents\Schemas;

use App\Models\CalendarEvent;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class CalendarEventForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Основная информация')
                    ->description('Название, тип и видимость события в календаре')
                    ->schema([
                        TextInput::make('title')
                            ->label('Название')
                            ->required()
                            ->maxLength(255)
                            ->columnSpanFull(),

                        Select::make('type')
                            ->label('Тип события')
                            ->options(CalendarEvent::typeOptions())
                            ->required()
                            ->native(false),

                        Toggle::make('is_active')
                            ->label('Активно')
                            ->helperText('Если выключено — событие не будет отображаться в календаре.')
                            ->default(true),

                        Textarea::make('description')
                            ->label('Описание')
                            ->rows(4)
                            ->placeholder('Необязательно')
                            ->columnSpanFull(),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),

                Section::make('Даты')
                    ->description('Период события. Если событие на один день — дату окончания можно не заполнять')
                    ->schema([
                        DatePicker::make('start_date')
                            ->label('Дата начала')
                            ->native(false)
                            ->required(),

                        DatePicker::make('end_date')
                            ->label('Дата окончания')
                            ->native(false)
                            ->afterOrEqual('start_date')
                            ->placeholder('Один день'),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),

                Section::make('Повторение')
                    ->description('Настройка повторяющихся событий')
                    ->schema([
                        Select::make('repeat_type')
                            ->label('Повторение')
                            ->options(CalendarEvent::repeatOptions())
                            ->default(CalendarEvent::REPEAT_NONE)
                            ->required()
                            ->native(false)
                            ->live(),

                        DatePicker::make('repeat_until')
                            ->label('Повторять до')
                            ->native(false)
                            ->visible(fn ($get): bool => $get('repeat_type') !== CalendarEvent::REPEAT_NONE)
                            ->afterOrEqual('start_date')
                            ->helperText('Можно оставить пустым.'),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),

                Section::make('Дополнительно')
                    ->description('Приоритет влияет на порядок отображения событи')
                    ->schema([
                        TextInput::make('priority')
                            ->label('Приоритет')
                            ->numeric()
                            ->default(0)
                            ->minValue(0)
                            ->helperText('Чем выше число, тем выше событие в календаре'),
                    ])
                    ->columns(2)
                    ->collapsed()
                    ->columnSpanFull(),
            ]);
    }
}