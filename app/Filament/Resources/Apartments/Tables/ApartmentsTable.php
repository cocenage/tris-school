<?php

namespace App\Filament\Resources\Apartments\Tables;

use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ApartmentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                ImageColumn::make('image')->label('Фото')->square(),

                TextColumn::make('name')
                    ->label('Название')
                    ->searchable()
                    ->sortable()
                    ->weight('medium'),

                TextColumn::make('code')
                    ->label('Код')
                    ->searchable()
                    ->toggleable(),

                TextColumn::make('address')
                    ->label('Адрес')
                    ->limit(50)
                    ->searchable()
                    ->toggleable(),

                IconColumn::make('is_active')
                    ->label('Активна')
                    ->boolean()
                    ->sortable(),

                TextColumn::make('information_status')
                    ->label('Страница')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'published' => 'Опубликована',
                        'archived' => 'Архив',
                        default => 'Черновик',
                    })
                    ->color(fn (?string $state): string => match ($state) {
                        'published' => 'success',
                        'archived' => 'gray',
                        default => 'warning',
                    })
                    ->sortable(),

                TextColumn::make('updated_at')
                    ->label('Обновлено')
                    ->dateTime('d.m.Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')->label('Активность'),
                Tables\Filters\SelectFilter::make('information_status')
                    ->label('Статус страницы')
                    ->options([
                        'draft' => 'Черновик',
                        'published' => 'Опубликована',
                        'archived' => 'Архив',
                    ]),
            ])
            ->actions([
                ViewAction::make(),
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->bulkActions([
                DeleteBulkAction::make(),
            ]);
    }
}
