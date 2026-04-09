<?php

namespace App\Filament\Resources\Users\Tables;

use App\Filament\Resources\Users\UserResource;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\BadgeColumn;
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
            ->defaultSort('name', 'asc')
            ->recordUrl(fn($record) => UserResource::getUrl('edit', ['record' => $record]))
            ->columns([
                ImageColumn::make('avatar')
                    ->label('')
                    ->getStateUsing(function ($record) {
                        if ($record->telegram_avatar_path) {
                            return $record->telegram_avatar_path;
                        }

                        return null;
                    })
                    ->disk('public')
                    ->circular()
                    ->size(48)
                    ->defaultImageUrl(asset('images/avatar-placeholder.png'))
                    ->grow(false),

                TextColumn::make('name')
                    ->label('Имя')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('telegram_username')
                    ->label('Тг')
                    ->searchable()
                    ->sortable()
                    ->formatStateUsing(fn(?string $state): string => filled($state) ? '@' . ltrim($state, '@') : '—')
                    ->toggleable(),

                TextColumn::make('work_started_at')
                    ->label('Начало работы')
                    ->formatStateUsing(fn($state) => $state?->translatedFormat('d F Y'))
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('birthday')
                    ->label('ДР')
                    ->formatStateUsing(fn($state) => $state?->translatedFormat('d F') ?? '—')
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('dip')
                    ->label('Dip')
                    ->badge()
                    ->formatStateUsing(fn(bool $state): string => $state ? 'dip' : 'no dip')
                    ->color(fn(bool $state): string => $state ? 'info' : 'gray')
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('role')
                    ->label('Роль')
                    ->badge()
                    ->icon(fn(string $state) => match ($state) {
                        'admin' => 'heroicon-m-shield-exclamation',
                        'supervisor' => 'heroicon-m-eye',
                        'cleaner' => 'heroicon-m-sparkles',
                        default => 'heroicon-m-user',
                    })
                    ->color(fn(string $state) => match ($state) {
                        'admin' => 'danger',
                        'supervisor' => 'warning',
                        'cleaner' => 'success',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn(string $state) => match ($state) {
                        'admin' => 'Админ',
                        'supervisor' => 'Супервайзер',
                        'cleaner' => 'Клинер',
                        default => $state,
                    })
                    ->toggleable(),

                TextColumn::make('status')
                    ->label('Доступ')
                    ->badge()
                    ->color(fn(string $state) => match ($state) {
                        'approved' => 'success',
                        'pending' => 'warning',
                        'rejected' => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn(string $state) => match ($state) {
                        'approved' => 'Одобрен',
                        'pending' => 'Ожидает',
                        'rejected' => 'Отклонён',
                        default => $state,
                    })
                    ->toggleable(),

                IconColumn::make('is_active')
                    ->label('Статус')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-x-circle')
                    ->trueColor('success')
                    ->falseColor('danger')
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('role')
                    ->label('Роль')
                    ->options([
                        'cleaner' => 'Клинер',
                        'supervisor' => 'Супервайзер',
                        'admin' => 'Админ',
                    ]),

                SelectFilter::make('dip')
                    ->label('Dip')
                    ->options([
                        '1' => 'Dip',
                        '0' => 'No dip',
                    ])
                    ->placeholder('Все'),

                SelectFilter::make('is_active')
                    ->label('Статус пользователя')
                    ->options([
                        '1' => 'Активные',
                        '0' => 'Неактивные',
                    ])
                    ->default('1')
                    ->placeholder('Все статусы'),

                SelectFilter::make('status')
                    ->label('Доступ')
                    ->options([
                        'pending' => 'Ожидает',
                        'approved' => 'Одобрен',
                        'rejected' => 'Отклонён',
                    ])
                    ->placeholder('Все'),
            ])
            ->actions([
                ActionGroup::make([
                    ViewAction::make(),
                    EditAction::make(),
                    DeleteAction::make(),
                ]),
            ])
            ->bulkActions([]);
    }
}