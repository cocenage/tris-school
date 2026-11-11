<?php

namespace App\Filament\Resources\SupervisorEmployees\Schemas;

use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class SupervisorEmployeeInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Сотрудник')
                ->columns(2)
                ->schema([
                    TextEntry::make('name')->label('Имя')->weight('bold'),
                    TextEntry::make('role')
                        ->label('Роль')
                        ->badge()
                        ->formatStateUsing(fn (?string $state): string => match ($state) {
                            'supervisor' => 'Супервайзер',
                            'cleaner' => 'Клинер',
                            default => '—',
                        }),
                    TextEntry::make('work_started_at')->label('Работает с')->date('d.m.Y')->placeholder('—'),
                    IconEntry::make('is_active')->label('Активен')->boolean(),
                ]),
        ]);
    }
}
