<?php

namespace App\Filament\Resources\Users\Tables;

use App\Filament\Resources\Users\UserResource;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->recordUrl(fn ($record) => UserResource::getUrl('edit', ['record' => $record]))

            ->columns([
                ImageColumn::make('telegram_avatar_path')
                    ->label('')
                    ->disk('public')
                    ->circular()
                    ->size(44)
                    ->defaultImageUrl(asset('images/avatar-placeholder.png'))
                    ->grow(false),

                TextColumn::make('name')
                    ->label('Пользователь')
                    ->searchable(['name', 'telegram_username', 'telegram_first_name', 'telegram_last_name', 'telegram_id'])
                    ->sortable()
                    ->weight('medium')
                    ->description(function ($record): string {
                        if (filled($record->telegram_username)) {
                            return '@' . ltrim($record->telegram_username, '@');
                        }

                        if (filled($record->telegram_first_name) || filled($record->telegram_last_name)) {
                            return trim($record->telegram_first_name . ' ' . $record->telegram_last_name);
                        }

                        return 'Telegram не указан';
                    }),

                TextColumn::make('role')
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
                        'admin' => 'Админ',
                        'supervisor' => 'Супервайзер',
                        'cleaner' => 'Клинер',
                        default => '—',
                    })
                    ->sortable(),

                TextColumn::make('status')
                    ->label('Доступ')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'approved' => 'success',
                        'pending' => 'warning',
                        'rejected' => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'approved' => 'Одобрен',
                        'pending' => 'Ожидает',
                        'rejected' => 'Отклонён',
                        default => '—',
                    })
                    ->sortable(),

                TextColumn::make('dip')
                    ->label('DIP')
                    ->badge()
                    ->formatStateUsing(fn (?bool $state): string => $state ? 'DIP' : 'NO DIP')
                    ->color(fn (?bool $state): string => $state ? 'info' : 'gray')
                    ->sortable()
                    ->toggleable(),

                IconColumn::make('is_active')
                    ->label('Активен')
                    ->boolean()
                    ->trueIcon('heroicon-m-check-circle')
                    ->falseIcon('heroicon-m-x-circle')
                    ->trueColor('success')
                    ->falseColor('danger')
                    ->sortable(),

                TextColumn::make('work_started_at')
                    ->label('Работает с')
                    ->date('d.m.Y')
                    ->placeholder('—')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('birthday')
                    ->label('ДР')
                    ->formatStateUsing(fn ($state) => $state?->translatedFormat('d F') ?? '—')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('last_login_at')
                    ->label('Последний вход')
                    ->since()
                    ->placeholder('—')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('created_at')
                    ->label('Создан')
                    ->dateTime('d.m.Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])

            ->filters([
                SelectFilter::make('is_active')
                    ->label('Активность')
                    ->options([
                        '1' => 'Активные',
                        '0' => 'Неактивные',
                    ])
                    ->placeholder('Все'),

                SelectFilter::make('status')
                    ->label('Доступ')
                    ->options([
                        'pending' => 'Ожидает',
                        'approved' => 'Одобрен',
                        'rejected' => 'Отклонён',
                    ])
                    ->placeholder('Все'),

                SelectFilter::make('role')
                    ->label('Роль')
                    ->options([
                        'cleaner' => 'Клинер',
                        'supervisor' => 'Супервайзер',
                        'admin' => 'Админ',
                    ])
                    ->placeholder('Все'),

                SelectFilter::make('dip')
                    ->label('DIP')
                    ->options([
                        '1' => 'DIP',
                        '0' => 'NO DIP',
                    ])
                    ->placeholder('Все'),
            ])

           ->actions([
    ActionGroup::make([
        Action::make('approveAccess')
            ->label('Одобрить доступ')
            ->icon('heroicon-m-check')
            ->color('success')
            ->visible(fn ($record): bool => $record->status !== 'approved')
            ->requiresConfirmation()
            ->action(function ($record): void {
                $record->update([
                    'status' => 'approved',
                    'approved_at' => now(),
                    'approved_by' => auth()->id(),
                ]);
            }),

        Action::make('rejectAccess')
            ->label('Запретить доступ')
            ->icon('heroicon-m-no-symbol')
            ->color('danger')
            ->visible(fn ($record): bool => $record->status !== 'rejected')
            ->requiresConfirmation()
            ->action(function ($record): void {
                $record->update([
                    'status' => 'rejected',
                ]);
            }),

        Action::make('makeEmployee')
            ->label('Вернуть в сотрудники')
            ->icon('heroicon-m-arrow-uturn-left')
            ->color('success')
            ->visible(fn ($record): bool => ! $record->is_active)
            ->requiresConfirmation()
            ->action(function ($record): void {
                $record->update([
                    'is_active' => true,
                ]);
            }),

        Action::make('fireEmployee')
            ->label('Уволить')
            ->icon('heroicon-m-user-minus')
            ->color('warning')
            ->visible(fn ($record): bool => $record->is_active)
            ->requiresConfirmation()
            ->action(function ($record): void {
                $record->update([
                    'is_active' => false,
                ]);
            }),

        ViewAction::make()
            ->label('Открыть'),

        EditAction::make()
            ->label('Редактировать'),

        DeleteAction::make()
            ->label('Удалить'),
    ]),
])

            ->bulkActions([]);
    }
}