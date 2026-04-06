<?php

namespace App\Filament\Resources\Users\Tables;

use Filament\Actions\EditAction;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Имя')
                    ->searchable(),

                TextColumn::make('telegram_username')
                    ->label('Telegram')
                    ->prefix('@')
                    ->searchable(),

                BadgeColumn::make('role')
                    ->label('Роль')
                    ->colors([
                        'gray' => 'cleaner',
                        'info' => 'supervisor',
                        'danger' => 'admin',
                    ]),

                BadgeColumn::make('status')
                    ->label('Статус')
                    ->colors([
                        'warning' => 'pending',
                        'success' => 'approved',
                        'danger' => 'rejected',
                    ]),
            ])
            ->actions([
                EditAction::make(),
            ]);
    }
}