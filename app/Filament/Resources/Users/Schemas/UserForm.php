<?php

namespace App\Filament\Resources\Users\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Grid::make(12)
                    ->schema([
                        Section::make('Основная информация')

                            ->schema([
                                TextInput::make('name')
                                    ->label('ФИО')
                                    ->required()
                                    ->maxLength(255)
                                    ->helperText('Если выключено - сотрудник считается неактивным, например уволенным'),


                                TextInput::make('telegram_username')
                                    ->label('Telegram')
                                    ->prefix('@')
                                    ->maxLength(255),

                                DatePicker::make('birthday')
                                    ->label('Дата рождения')
                                    ->displayFormat('d.m.Y')
                                    ->format('Y-m-d')
                                    ->placeholder('дд.мм.гггг')
                                    ->native(false),

                                DatePicker::make('work_started_at')
                                    ->label('Начало работы')
                                    ->displayFormat('d.m.Y')
                                    ->format('Y-m-d')
                                    ->placeholder('дд.мм.гггг')
                                    ->native(false)
                                    ->default(fn() => now()->toDateString())
                                    ->afterStateHydrated(function ($component, $state, $record) {
                                        if (filled($state)) {
                                            return;
                                        }

                                        if ($record?->created_at) {
                                            $component->state($record->created_at->toDateString());
                                            return;
                                        }

                                        $component->state(now()->toDateString());
                                    }),

                                Select::make('role')
                                    ->label('Роль')
                                    ->options([
                                        'cleaner' => 'Клинер',
                                        'supervisor' => 'Супервайзер',
                                        'admin' => 'Администратор',
                                    ])
                                    ->required()
                                    ->native(false),

                                Select::make('status')
                                    ->label('Доступ')
                                    ->options([
                                        'pending' => 'Ожидает',
                                        'approved' => 'Одобрен',
                                        'rejected' => 'Отклонён',
                                    ])
                                    ->required()
                                    ->default('pending')
                                    ->native(false),
                            ])
                            ->columns(2)
                            ->columnSpan([
                                'default' => 12,
                                'xl' => 8,
                            ]),

                        Section::make('Статусы')
                            ->schema([
                                Toggle::make('dip')
                                    ->label('DIP')
                                    ->default(false),

                                Toggle::make('is_active')
                                    ->label('Активный сотрудник')
                                    ->default(true)
                                    ->helperText('Если выключено - сотрудник считается неактивным, например уволенным'),
                            ])
                            ->columnSpan([
                                'default' => 12,
                                'xl' => 4,
                            ]),
                    ]),
            ]);
    }
}