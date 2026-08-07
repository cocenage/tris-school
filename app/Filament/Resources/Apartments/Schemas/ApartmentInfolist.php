<?php

namespace App\Filament\Resources\Apartments\Schemas;

use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ApartmentInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(['default' => 1, 'lg' => 3])->schema([
                Section::make('Основная информация')
                    ->columnSpan(['default' => 1, 'lg' => 2])
                    ->schema([
                        TextEntry::make('name')->label('Название'),
                        TextEntry::make('code')->label('Код объекта')->placeholder('—'),
                        TextEntry::make('address')->label('Адрес')->placeholder('—'),
                        ImageEntry::make('image')->label('Фото')->height(220)->columnSpanFull(),
                        TextEntry::make('notes')
                            ->label('Служебная заметка')
                            ->placeholder('—')
                            ->columnSpanFull(),
                    ]),

                Section::make('Статус')
                    ->columnSpan(['default' => 1, 'lg' => 1])
                    ->schema([
                        IconEntry::make('is_active')->label('Активна')->boolean(),
                        TextEntry::make('information_status')
                            ->label('Статус страницы')
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
                            }),
                        TextEntry::make('informationUpdatedBy.name')
                            ->label('Последнее изменение')
                            ->placeholder('—'),
                        TextEntry::make('information_updated_at')
                            ->label('Дата обновления')
                            ->dateTime('d.m.Y H:i')
                            ->placeholder('—'),
                    ]),
            ]),
        ]);
    }
}
