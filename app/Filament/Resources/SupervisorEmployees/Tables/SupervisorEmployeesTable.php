<?php

namespace App\Filament\Resources\SupervisorEmployees\Tables;

use App\Filament\Resources\SupervisorEmployees\SupervisorEmployeeResource;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class SupervisorEmployeesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->recordUrl(fn ($record): string => SupervisorEmployeeResource::getUrl('view', ['record' => $record]))
            ->columns([
                TextColumn::make('name')->label('Сотрудник')->searchable()->sortable()->weight('medium'),
                TextColumn::make('role')
                    ->label('Роль')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => $state === 'supervisor' ? 'Супервайзер' : 'Клинер'),
                TextColumn::make('work_started_at')->label('Работает с')->date('d.m.Y')->placeholder('—')->sortable(),
                IconColumn::make('is_active')->label('Активен')->boolean(),
            ])
            ->actions([ViewAction::make()->label('Карточка')])
            ->toolbarActions([]);
    }
}
