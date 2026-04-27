<?php

namespace App\Filament\Resources\Users\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Доступ и роль')
                    ->description('Главное: может ли пользователь заходить на сайт, какая у него роль и работает ли он сейчас')
                    ->schema([
                        Select::make('status')
                            ->label('Доступ к сайту')
                            ->options([
                                'pending' => 'Ожидает доступ',
                                'approved' => 'Доступ разрешён',
                                'rejected' => 'Доступ запрещён',
                            ])
                            ->required()
                            ->default('pending')
                            ->native(false),

                        Select::make('role')
                            ->label('Роль')
                            ->options([
                                'cleaner' => 'Клинер',
                                'supervisor' => 'Супервайзер',
                                'admin' => 'Администратор',
                            ])
                            ->required()
                            ->native(false),

                        Toggle::make('is_active')
                            ->label('Работает в компании')
                            ->default(true)
                            ->helperText('Выключи, если сотрудник уволен или больше не работает'),

                        Toggle::make('dip')
                            ->label('DIP')
                            ->default(false)
                             ->helperText('Выключи, если сотрудник Dip'),
                    ])
                    ->columns(2),

                Section::make('Личная информация')
                    ->schema([
                        TextInput::make('name')
                            ->label('ФИО')
                            ->required()
                            ->maxLength(255),

                        TextInput::make('email')
                            ->label('Email')
                            ->email()
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
                            ->default(fn () => now()->toDateString())
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
                    ])
                    ->columns(2),

                Section::make('Telegram')
                    ->description('Данные, которые пришли из Telegram Mini App')
                    ->schema([
                        Placeholder::make('avatar_preview')
                            ->label('Аватар')
                            ->content(function ($record) {
                                if (! $record?->avatar_url) {
                                    return 'Нет аватара';
                                }

                                return new HtmlString(
                                    '<img src="' . e($record->avatar_url) . '" class="h-20 w-20 rounded-full object-cover ring-2 ring-gray-200" />'
                                );
                            }),

                        TextInput::make('telegram_username')
                            ->label('Username')
                            ->prefix('@')
                            ->maxLength(255),

                        TextInput::make('telegram_id')
                            ->label('Telegram ID')
                            ->maxLength(255)
                            ->disabled()
                            ->dehydrated(true),

                        TextInput::make('telegram_first_name')
                            ->label('Имя в Telegram')
                            ->maxLength(255),

                        TextInput::make('telegram_last_name')
                            ->label('Фамилия в Telegram')
                            ->maxLength(255),

                        TextInput::make('telegram_photo_url')
                            ->label('Telegram photo URL')
                            ->maxLength(1000)
                            ->disabled()
                            ->dehydrated(true),

                        TextInput::make('telegram_avatar_path')
                            ->label('Путь к аватару')
                            ->maxLength(255)
                            ->disabled()
                            ->dehydrated(true),

                        TextInput::make('telegram_login_source')
                            ->label('Источник входа')
                            ->maxLength(255)
                            ->disabled()
                            ->dehydrated(true),
                    ])
                    ->columns(2),

                Section::make('Доступ к типам календаря')
                    ->description('Какие типы событий пользователь будет видеть в календаре')
                    ->schema([
                        Toggle::make('calendar_workflow')
                            ->label('Рабочие процессы')
                            ->dehydrated(false),

                        Toggle::make('calendar_finance')
                            ->label('Финансы')
                            ->dehydrated(false),

                        Toggle::make('calendar_holiday')
                            ->label('Праздники')
                            ->dehydrated(false),

                        Toggle::make('calendar_peak')
                            ->label('Пики загрузки')
                            ->dehydrated(false),

                        Toggle::make('calendar_vacation')
                            ->label('Выходные и отпуска')
                            ->dehydrated(false),

                        Toggle::make('calendar_strike')
                            ->label('Забастовки')
                            ->dehydrated(false),
                    ])
                    ->columns(2),

                Section::make('Доступ к панелям')
                    ->description('Админ видит все панели автоматически. Для остальных доступ выдаётся здесь')
                    ->schema([
                        Toggle::make('access_finance')
                            ->label('Финансы')
                            ->dehydrated(false),

                        Toggle::make('access_education')
                            ->label('Обучение')
                            ->helperText('Контроли, коучинги и календари')
                            ->dehydrated(false),
                    ])
                    ->columns(2),

                Section::make('Системная информация')
                    ->description('Технические данные пользователя. Обычно редактировать их не нужно')
                    ->schema([
                        Placeholder::make('id')
                            ->label('ID')
                            ->content(fn ($record) => $record?->id ?? 'Новый пользователь'),

                        Placeholder::make('approved_at')
                            ->label('Доступ одобрен')
                            ->content(fn ($record) => $record?->approved_at?->format('d.m.Y H:i') ?? 'Нет'),

                        Placeholder::make('approved_by')
                            ->label('Кем одобрен')
                            ->content(fn ($record) => $record?->approved_by ?? 'Нет'),

                        Placeholder::make('last_login_at')
                            ->label('Последний вход')
                            ->content(fn ($record) => $record?->last_login_at?->format('d.m.Y H:i') ?? 'Нет'),

                        Placeholder::make('telegram_write_access_granted_at')
                            ->label('Доступ к сообщениям Telegram')
                            ->content(fn ($record) => $record?->telegram_write_access_granted_at?->format('d.m.Y H:i') ?? 'Нет'),

                        Placeholder::make('telegram_last_auth_at')
                            ->label('Последняя авторизация Telegram')
                            ->content(fn ($record) => $record?->telegram_last_auth_at?->format('d.m.Y H:i') ?? 'Нет'),

                        Placeholder::make('email_verified_at')
                            ->label('Email подтверждён')
                            ->content(fn ($record) => $record?->email_verified_at?->format('d.m.Y H:i') ?? 'Нет'),

                        Placeholder::make('created_at')
                            ->label('Создан')
                            ->content(fn ($record) => $record?->created_at?->format('d.m.Y H:i') ?? 'Нет'),

                        Placeholder::make('updated_at')
                            ->label('Обновлён')
                            ->content(fn ($record) => $record?->updated_at?->format('d.m.Y H:i') ?? 'Нет'),
                    ])
                    ->columns(3),
            ]);
    }
}