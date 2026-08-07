<?php

namespace App\Filament\Resources\Apartments\Schemas;

use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ApartmentForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(['default' => 1, 'lg' => 3])->schema([
                Section::make('Основная информация')
                    ->columnSpan(['default' => 1, 'lg' => 2])
                    ->schema([
                        Grid::make(['default' => 1, 'md' => 2])->schema([
                            TextInput::make('name')
                                ->label('Название')
                                ->required()
                                ->maxLength(255),

                            TextInput::make('code')
                                ->label('Код объекта')
                                ->maxLength(100),
                        ]),

                        Textarea::make('address')
                            ->label('Адрес')
                            ->rows(3)
                            ->columnSpanFull(),

                        FileUpload::make('image')
                            ->label('Фото объекта')
                            ->image()
                            ->imageEditor()
                            ->disk('public')
                            ->directory('apartments')
                            ->visibility('public')
                            ->maxSize(10240)
                            ->columnSpanFull(),

                        Textarea::make('notes')
                            ->label('Служебная заметка')
                            ->rows(4)
                            ->maxLength(20000)
                            ->columnSpanFull(),
                    ]),

                Section::make('Настройки')
                    ->columnSpan(['default' => 1, 'lg' => 1])
                    ->schema([
                        Toggle::make('is_active')
                            ->label('Активна')
                            ->default(true),

                        Select::make('information_status')
                            ->label('Статус информационной страницы')
                            ->options([
                                'draft' => 'Черновик',
                                'published' => 'Опубликована',
                                'archived' => 'Архив',
                            ])
                            ->required()
                            ->default('published'),
                    ]),
            ]),
        ]);
    }
}
