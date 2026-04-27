<?php

namespace App\Filament\Resources\Users\Schemas;

use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class UserInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Grid::make(12)
                    ->schema([
                        Section::make('Пользователь')
                            ->description('Основная карточка сотрудника.')
                            ->schema([
                                TextEntry::make('name')
                                    ->label('ФИО')
                                    ->weight('bold')
                                    ->size('lg')
                                    ->columnSpanFull(),

                                TextEntry::make('telegram_username')
                                    ->label('Telegram')
                                    ->formatStateUsing(fn (?string $state): string => filled($state) ? '@' . ltrim($state, '@') : '—')
                                    ->copyable(),

                                TextEntry::make('email')
                                    ->label('Email')
                                    ->placeholder('—')
                                    ->copyable(),

                                TextEntry::make('role')
                                    ->label('Роль')
                                    ->badge()
                                    ->icon(fn (?string $state): string => match ($state) {
                                        'admin' => 'heroicon-m-shield-exclamation',
                                        'supervisor' => 'heroicon-m-eye',
                                        'cleaner' => 'heroicon-m-sparkles',
                                        default => 'heroicon-m-user',
                                    })
                                    ->color(fn (?string $state): string => match ($state) {
                                        'admin' => 'danger',
                                        'supervisor' => 'warning',
                                        'cleaner' => 'success',
                                        default => 'gray',
                                    })
                                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                                        'admin' => 'Администратор',
                                        'supervisor' => 'Супервайзер',
                                        'cleaner' => 'Клинер',
                                        default => '—',
                                    }),
                            ])
                            ->columns(2)
                            ->columnSpan([
                                'default' => 12,
                                'xl' => 8,
                            ]),

                        Section::make('Статусы')
                            ->description('Доступ к сайту и рабочий статус — это разные вещи.')
                            ->schema([
                                TextEntry::make('status')
                                    ->label('Доступ к сайту')
                                    ->badge()
                                    ->color(fn (?string $state): string => match ($state) {
                                        'approved' => 'success',
                                        'pending' => 'warning',
                                        'rejected' => 'danger',
                                        default => 'gray',
                                    })
                                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                                        'approved' => 'Доступ разрешён',
                                        'pending' => 'Ожидает доступ',
                                        'rejected' => 'Доступ запрещён',
                                        default => '—',
                                    }),

                                IconEntry::make('is_active')
                                    ->label('Работает в компании')
                                    ->boolean()
                                    ->trueIcon('heroicon-m-check-circle')
                                    ->falseIcon('heroicon-m-user-minus')
                                    ->trueColor('success')
                                    ->falseColor('danger'),

                                TextEntry::make('dip')
                                    ->label('DIP')
                                    ->badge()
                                    ->color(fn (?bool $state): string => $state ? 'info' : 'gray')
                                    ->formatStateUsing(fn (?bool $state): string => $state ? 'DIP' : 'NO DIP'),
                            ])
                            ->columns(1)
                            ->columnSpan([
                                'default' => 12,
                                'xl' => 4,
                            ]),

                        Section::make('Рабочая информация')
                            ->schema([
                                TextEntry::make('birthday')
                                    ->label('Дата рождения')
                                    ->date('d.m.Y')
                                    ->placeholder('—'),

                                TextEntry::make('work_started_at')
                                    ->label('Начало работы')
                                    ->date('d.m.Y')
                                    ->placeholder('—'),

                                TextEntry::make('last_login_at')
                                    ->label('Последний вход')
                                    ->dateTime('d.m.Y H:i')
                                    ->placeholder('—'),
                            ])
                            ->columns(3)
                            ->columnSpan(12),

                        Section::make('Telegram')
                            ->schema([
                                TextEntry::make('telegram_id')
                                    ->label('Telegram ID')
                                    ->placeholder('—')
                                    ->copyable(),

                                TextEntry::make('telegram_first_name')
                                    ->label('Имя в Telegram')
                                    ->placeholder('—'),

                                TextEntry::make('telegram_last_name')
                                    ->label('Фамилия в Telegram')
                                    ->placeholder('—'),

                                TextEntry::make('telegram_photo_url')
                                    ->label('Photo URL')
                                    ->placeholder('—')
                                    ->copyable(),

                                TextEntry::make('telegram_avatar_path')
                                    ->label('Путь к аватару')
                                    ->placeholder('—')
                                    ->copyable(),

                                TextEntry::make('telegram_login_source')
                                    ->label('Источник входа')
                                    ->placeholder('—'),
                            ])
                            ->columns(2)
                            ->columnSpan(12),

                        Section::make('Системная информация')
                            ->schema([
                                TextEntry::make('id')
                                    ->label('ID')
                                    ->copyable(),

                                TextEntry::make('approved_at')
                                    ->label('Доступ одобрен')
                                    ->dateTime('d.m.Y H:i')
                                    ->placeholder('—'),

                                TextEntry::make('approved_by')
                                    ->label('Кем одобрен')
                                    ->placeholder('—'),

                                TextEntry::make('telegram_write_access_granted_at')
                                    ->label('Доступ к сообщениям Telegram')
                                    ->dateTime('d.m.Y H:i')
                                    ->placeholder('—'),

                                TextEntry::make('telegram_last_auth_at')
                                    ->label('Последняя авторизация Telegram')
                                    ->dateTime('d.m.Y H:i')
                                    ->placeholder('—'),

                                TextEntry::make('email_verified_at')
                                    ->label('Email подтверждён')
                                    ->dateTime('d.m.Y H:i')
                                    ->placeholder('—'),

                                TextEntry::make('created_at')
                                    ->label('Создан')
                                    ->dateTime('d.m.Y H:i')
                                    ->placeholder('—'),

                                TextEntry::make('updated_at')
                                    ->label('Обновлён')
                                    ->dateTime('d.m.Y H:i')
                                    ->placeholder('—'),
                            ])
                            ->columns(3)
                            ->columnSpan(12),
                    ]),
            ]);
    }
}