<?php

namespace App\Filament\Resources\InstructionCategories\Schemas;

use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class InstructionCategoryForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Основное')
                ->schema([
                    Grid::make(2)->schema([
                        TextInput::make('title')
                            ->label('Название')
                            ->required()
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn ($state, callable $set) => $set('slug', Str::slug($state))),

                        TextInput::make('slug')
                            ->label('Slug')
                            ->required()
                            ->unique(ignoreRecord: true),
                    ]),

                    Textarea::make('description')
                        ->label('Описание')
                        ->rows(3)
                        ->columnSpanFull(),

                    Grid::make(3)->schema([
                        TextInput::make('emoji')
                            ->label('Emoji')
                            ->maxLength(10),

                        TextInput::make('icon')
                            ->label('Иконка')
                            ->placeholder('heroicon-o-book-open'),

                        ColorPicker::make('color')
                            ->label('Цвет'),
                    ]),

                    Grid::make(2)->schema([
                        TextInput::make('sort_order')
                            ->label('Сортировка')
                            ->numeric()
                            ->default(0),

                        Toggle::make('is_active')
                            ->label('Активна')
                            ->default(true),
                    ]),
                ]),
        ]);
    }
}