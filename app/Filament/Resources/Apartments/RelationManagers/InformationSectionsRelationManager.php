<?php

namespace App\Filament\Resources\Apartments\RelationManagers;

use App\Models\ApartmentInformationSection;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class InformationSectionsRelationManager extends RelationManager
{
    protected static string $relationship = 'informationSections';

    protected static ?string $title = 'Информационные разделы';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('type')
                ->label('Тип раздела')
                ->options([
                    'general' => 'Общее',
                    'access' => 'Доступ',
                    'keys' => 'Ключи',
                    'cleaning' => 'Уборка',
                    'laundry' => 'Бельё',
                    'supplies' => 'Расходники',
                    'appliances' => 'Бытовая техника',
                    'wifi' => 'Wi‑Fi',
                    'warnings' => 'Предупреждения',
                    'contacts' => 'Контакты',
                    'custom' => 'Другое',
                ])
                ->required()
                ->default('general'),

            TextInput::make('title')
                ->label('Заголовок')
                ->required()
                ->maxLength(255),

            Textarea::make('content')
                ->label('Содержание')
                ->required()
                ->maxLength(20000)
                ->rows(8)
                ->columnSpanFull(),

            TextInput::make('sort_order')
                ->label('Порядок')
                ->numeric()
                ->minValue(0)
                ->default(0),

            Toggle::make('is_visible')
                ->label('Показывать сотрудникам')
                ->default(true),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->columns([
                TextColumn::make('title')
                    ->label('Раздел')
                    ->searchable()
                    ->sortable()
                    ->weight('medium'),

                TextColumn::make('type')
                    ->label('Тип')
                    ->formatStateUsing(fn (?string $state): string => self::typeLabel($state)),

                IconColumn::make('is_visible')
                    ->label('Виден')
                    ->boolean(),

                TextColumn::make('sort_order')
                    ->label('Порядок')
                    ->sortable(),

                TextColumn::make('updatedBy.name')
                    ->label('Изменил')
                    ->placeholder('—'),

                TextColumn::make('updated_at')
                    ->label('Обновлено')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
            ])
            ->headerActions([
                CreateAction::make()->label('Добавить раздел'),
            ])
            ->actions([
                EditAction::make()->label('Изменить'),
                DeleteAction::make()->label('Удалить')->requiresConfirmation(),
            ]);
    }

    private static function typeLabel(?string $type): string
    {
        return [
            'general' => 'Общее',
            'access' => 'Доступ',
            'keys' => 'Ключи',
            'cleaning' => 'Уборка',
            'laundry' => 'Бельё',
            'supplies' => 'Расходники',
            'appliances' => 'Бытовая техника',
            'wifi' => 'Wi‑Fi',
            'warnings' => 'Предупреждения',
            'contacts' => 'Контакты',
            'custom' => 'Другое',
        ][$type] ?? 'Другое';
    }
}
