<?php

namespace App\Filament\Resources\Controls\Schemas;

use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ControlInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make([
                'default' => 1,
                'lg' => 3,
            ])->schema([
                Section::make('Основная информация')
                    ->columnSpan([
                        'default' => 1,
                        'lg' => 2,
                    ])
                    ->schema([
                        TextEntry::make('name')
                            ->label('Название'),

                        TextEntry::make('slug')
                            ->label('Slug'),

                        TextEntry::make('description')
                            ->label('Описание')
                            ->placeholder('—')
                            ->columnSpanFull(),

                        ImageEntry::make('image')
                            ->label('Обложка')
                            ->height(220)
                            ->columnSpanFull(),
                    ]),

                Section::make('Статус')
                    ->columnSpan([
                        'default' => 1,
                        'lg' => 1,
                    ])
                    ->schema([
                        IconEntry::make('is_active')
                            ->label('Активен')
                            ->boolean(),

                        TextEntry::make('updated_at')
                            ->label('Обновлено')
                            ->dateTime('d.m.Y H:i'),
                    ]),
            ]),
        ]);
    }
}