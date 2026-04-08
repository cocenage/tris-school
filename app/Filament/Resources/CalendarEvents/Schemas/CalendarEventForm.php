<?php

namespace App\Filament\Resources\CalendarEvents\Schemas;

use App\Models\CalendarEvent;
use Filament\Forms\Components\DatePicker;


use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class CalendarEventForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Основная информация')
                    ->schema([
                        Grid::make(2)
                            ->schema([
                                Select::make('type')
                                    ->label('Тип события')
                                    ->options(CalendarEvent::typeOptions())
                                    ->required()
                                    ->native(false),

                                Toggle::make('is_active')
                                    ->label('Активно')
                                    ->default(true),
                            ]),

                        TextInput::make('title')
                            ->label('Название')
                            ->required()
                            ->maxLength(255),

                        Textarea::make('description')
                            ->label('Описание')
                            ->rows(4)
                            ->columnSpanFull(),
                    ]),

                Section::make('Даты')
                    ->schema([
                        Grid::make(2)
                            ->schema([
                                DatePicker::make('start_date')
                                    ->label('Дата начала')
                                    ->required(),

                                DatePicker::make('end_date')
                                    ->label('Дата окончания')
                                    ->afterOrEqual('start_date')
                                    ->helperText('Оставь пустым, если событие на один день'),
                            ]),
                    ]),

                Section::make('Повторение')
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
                            ->visible(fn ($get) => $get('repeat_type') !== CalendarEvent::REPEAT_NONE)
                            ->afterOrEqual('start_date')
                            ->helperText('Необязательно'),
                    ]),

                Section::make('Дополнительно')
                    ->collapsed()
                    ->schema([
                        TextInput::make('priority')
                            ->label('Приоритет')
                            ->numeric()
                            ->default(0)
                            ->minValue(0),
                    ]),
            ]);
    }
}