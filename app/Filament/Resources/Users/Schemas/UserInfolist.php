<?php

namespace App\Filament\Resources\Users\Schemas;

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
                        Section::make('Основная информация')
                            ->schema([
                                TextEntry::make('name')
                                    ->label('Имя')
                                    ->weight('bold')
                                    ->size('lg')
                                    ->columnSpanFull(),

                                TextEntry::make('telegram_username')
                                    ->label('Telegram')
                                    ->formatStateUsing(
                                        fn(?string $state): string => filled($state)
                                        ? '@' . ltrim($state, '@')
                                        : '—'
                                    )
                                    ->copyable()
                                    ->placeholder('—'),

                                TextEntry::make('role')
                                    ->label('Роль')
                                    ->badge()
                                    ->icon(function (?string $state): string {
                                        $state = strtolower(trim((string) $state));

                                        return match ($state) {
                                            'admin' => 'heroicon-m-shield-exclamation',
                                            'supervisor' => 'heroicon-m-eye',
                                            'cleaner' => 'heroicon-m-sparkles',
                                            default => 'heroicon-m-user',
                                        };
                                    })
                                    ->color(function (?string $state): string {
                                        $state = strtolower(trim((string) $state));

                                        return match ($state) {
                                            'admin' => 'danger',
                                            'supervisor' => 'warning',
                                            'cleaner' => 'success',
                                            default => 'gray',
                                        };
                                    })
                                    ->formatStateUsing(function (?string $state): string {
                                        $state = strtolower(trim((string) $state));

                                        return match ($state) {
                                            'admin' => 'Администратор',
                                            'supervisor' => 'Супервайзер',
                                            'cleaner' => 'Клинер',
                                            default => '—',
                                        };
                                    }),

                                TextEntry::make('status')
                                    ->label('Доступ')
                                    ->badge()
                                    ->color(function (?string $state): string {
                                        $state = strtolower(trim((string) $state));

                                        return match ($state) {
                                            'approved' => 'success',
                                            'pending' => 'warning',
                                            'rejected' => 'danger',
                                            default => 'gray',
                                        };
                                    })
                                    ->formatStateUsing(function (?string $state): string {
                                        $state = strtolower(trim((string) $state));

                                        return match ($state) {
                                            'approved' => 'Одобрен',
                                            'pending' => 'Ожидает',
                                            'rejected' => 'Отклонён',
                                            default => '—',
                                        };
                                    }),
                            ])
                            ->columns(2)
                            ->columnSpan([
                                'default' => 12,
                                'xl' => 8,
                            ]),

                        Section::make('Статусы')
                            ->schema([
                                TextEntry::make('dip')
                                    ->label('DIP')
                                    ->badge()
                                    ->color(fn(bool $state): string => $state ? 'info' : 'gray')
                                    ->formatStateUsing(fn(bool $state): string => $state ? 'Dip' : 'No dip'),

                                TextEntry::make('is_active')
                                    ->label('Сотрудник')
                                    ->badge()
                                    ->color(fn(bool $state): string => $state ? 'success' : 'danger')
                                    ->formatStateUsing(fn(bool $state): string => $state ? 'Активный' : 'Неактивный'),
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
                            ])
                            ->columns(2)
                            ->columnSpan(12),

                        Section::make('Системная информация')
                            ->schema([
                                TextEntry::make('telegram_id')
                                    ->label('Telegram ID')
                                    ->placeholder('—')
                                    ->copyable(),

                                TextEntry::make('approved_at')
                                    ->label('Одобрен')
                                    ->dateTime('d.m.Y H:i')
                                    ->placeholder('—'),

                                TextEntry::make('last_login_at')
                                    ->label('Последний вход')
                                    ->dateTime('d.m.Y H:i')
                                    ->placeholder('—'),

                                TextEntry::make('created_at')
                                    ->label('Создан')
                                    ->dateTime('d.m.Y H:i')
                                    ->placeholder('—'),
                            ])
                            ->columns(2)
                            ->columnSpan(12),
                    ]),
            ]);
    }
}