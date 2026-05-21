<?php

namespace App\Filament\Resources\TelegramTopics;

use App\Filament\Resources\TelegramTopics\Pages\EditTelegramTopic;
use App\Filament\Resources\TelegramTopics\Pages\ListTelegramTopics;
use App\Models\TelegramTopic;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class TelegramTopicResource extends Resource
{
    protected static ?string $model = TelegramTopic::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-hashtag';

    protected static string|UnitEnum|null $navigationGroup = 'Аналитика';

    protected static ?string $navigationLabel = 'Telegram топики';

    protected static ?string $modelLabel = 'Telegram топик';

    protected static ?string $pluralModelLabel = 'Telegram топики';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('telegram_chat_id')
                ->label('Чат')
                ->relationship('chat', 'title')
                ->disabled(),

            TextInput::make('telegram_thread_id')
                ->label('Thread ID')
                ->disabled(),

            TextInput::make('title')
                ->label('Название топика')
                ->placeholder('Например: Жалобы / Фотоотчеты / Заявки')
                ->maxLength(255),

            Select::make('purpose')
                ->label('Назначение')
                ->options([
                    'cleaning' => 'Уборки',
                    'complaints' => 'Жалобы',
                    'reports' => 'Фотоотчеты',
                    'tasks' => 'Задачи',
                    'staff' => 'Сотрудники',
                    'admin' => 'Админское',
                    'salary' => 'Зарплата',
                    'vacation' => 'Отпуск',
                    'day_off' => 'Выходные',
                    'other' => 'Другое',
                ])
                ->searchable(),

            Toggle::make('is_enabled')
                ->label('Активен')
                ->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('updated_at', 'desc')
            ->columns([
                TextColumn::make('chat.title')
                    ->label('Чат')
                    ->searchable()
                    ->placeholder('—'),

                TextColumn::make('telegram_thread_id')
                    ->label('Thread ID')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('title')
                    ->label('Название')
                    ->searchable()
                    ->placeholder('Не подписан'),

                TextColumn::make('purpose')
                    ->label('Назначение')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'cleaning' => 'Уборки',
                        'complaints' => 'Жалобы',
                        'reports' => 'Фотоотчеты',
                        'tasks' => 'Задачи',
                        'staff' => 'Сотрудники',
                        'admin' => 'Админское',
                        'salary' => 'Зарплата',
                        'vacation' => 'Отпуск',
                        'day_off' => 'Выходные',
                        'other' => 'Другое',
                        default => '—',
                    }),

                IconColumn::make('is_enabled')
                    ->label('Активен')
                    ->boolean(),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTelegramTopics::route('/'),
            'edit' => EditTelegramTopic::route('/{record}/edit'),
        ];
    }
}