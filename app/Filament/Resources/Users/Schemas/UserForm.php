<?php

namespace App\Filament\Resources\Users\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Имя')
                    ->required(),

                TextInput::make('telegram_username')
                    ->label('Telegram')
                    ->prefix('@'),

                Select::make('role')
                    ->label('Роль')
                    ->options(function () {
                        $options = [
                            'cleaner' => 'Клинер',
                            'supervisor' => 'Супервайзер',
                        ];

                        if (!\App\Models\User::where('role', 'admin')->exists()) {
                            $options['admin'] = 'Админ';
                        }

                        return $options;
                    })
                    ->required(),

                Select::make('status')
                    ->label('Доступ')
                    ->options([
                        'pending' => 'Ожидает',
                        'approved' => 'Одобрен',
                        'rejected' => 'Отклонён',
                    ])
                    ->required(),
            ]);
    }
}