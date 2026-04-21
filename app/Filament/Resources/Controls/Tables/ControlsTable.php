<?php

namespace App\Filament\Resources\Controls\Tables;

use App\Models\Control;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class ControlsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')
                    ->label('Название')
                    ->searchable()
                    ->sortable()
                    ->weight('medium'),

                TextColumn::make('slug')
                    ->label('Slug')
                    ->searchable()
                    ->toggleable(),

                TextColumn::make('updated_at')
                    ->label('Обновлено')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),

                IconColumn::make('is_active')
                    ->label('Активен')
                    ->boolean()
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Активность'),
            ])
            ->actions([
                ViewAction::make(),
                EditAction::make(),
                DeleteAction::make(),

                Action::make('export_json')
                    ->label('Экспорт')
                    ->icon(Heroicon::ArrowDownTray)
                    ->color('gray')
                    ->action(function (Control $record) {
                        return response()->streamDownload(function () use ($record): void {
                            echo json_encode([
                                'meta' => [
                                    'exported_at' => now()->toDateTimeString(),
                                    'version' => 4,
                                    'type' => 'control',
                                ],
                                'data' => [
                                    'name' => $record->name,
                                    'slug' => $record->slug,
                                    'description' => $record->description,
                                    'image' => $record->image,
                                    'is_active' => $record->is_active,
                                    'main' => $record->main,
                                ],
                            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                        }, Str::slug($record->name ?: 'control') . '.json');
                    }),
            ])
            ->bulkActions([
                DeleteBulkAction::make(),
            ]);
    }
}