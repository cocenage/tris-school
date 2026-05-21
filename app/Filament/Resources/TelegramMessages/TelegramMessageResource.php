<?php

namespace App\Filament\Resources\TelegramMessages;

use App\Filament\Resources\TelegramMessages\Pages\ListTelegramMessages;
use App\Filament\Resources\TelegramMessages\Pages\ViewTelegramMessage;
use App\Models\TelegramMessage;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class TelegramMessageResource extends Resource
{
    protected static ?string $model = TelegramMessage::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-chat-bubble-left-right';

    protected static string|UnitEnum|null $navigationGroup = 'Аналитика';

    protected static ?string $navigationLabel = 'Telegram сообщения';

    protected static ?string $modelLabel = 'Telegram сообщение';

    protected static ?string $pluralModelLabel = 'Telegram сообщения';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('message_id')
                ->label('ID сообщения')
                ->disabled(),

            TextInput::make('message_type')
                ->label('Тип')
                ->disabled(),

            Textarea::make('text')
                ->label('Текст')
                ->rows(8)
                ->disabled(),

            Textarea::make('caption')
                ->label('Подпись')
                ->rows(4)
                ->disabled(),

            DateTimePicker::make('sent_at')
                ->label('Отправлено')
                ->disabled(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sent_at', 'desc')
            ->columns([
                TextColumn::make('sent_at')
                    ->label('Время')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),

                TextColumn::make('chat.title')
                    ->label('Чат')
                    ->placeholder('—'),

                TextColumn::make('topic.telegram_thread_id')
                    ->label('Топик')
                    ->placeholder('—'),

                TextColumn::make('telegramUser.full_name')
                    ->label('Кто')
                    ->placeholder('—'),

                TextColumn::make('message_type')
                    ->label('Тип')
                    ->badge(),

                TextColumn::make('text')
                    ->label('Текст')
                    ->limit(90)
                    ->searchable()
                    ->placeholder('—'),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTelegramMessages::route('/'),
            'view' => ViewTelegramMessage::route('/{record}'),
        ];
    }
}