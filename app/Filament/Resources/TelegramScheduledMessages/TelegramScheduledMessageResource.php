<?php

namespace App\Filament\Resources\TelegramScheduledMessages;

use App\Filament\Resources\TelegramScheduledMessages\Pages\CreateTelegramScheduledMessage;
use App\Filament\Resources\TelegramScheduledMessages\Pages\EditTelegramScheduledMessage;
use App\Filament\Resources\TelegramScheduledMessages\Pages\ListTelegramScheduledMessages;
use App\Models\TelegramScheduledMessage;
use App\Services\Telegram\TelegramDestinationCatalog;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class TelegramScheduledMessageResource extends Resource
{
    protected static ?string $model = TelegramScheduledMessage::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-clock';

    protected static string|UnitEnum|null $navigationGroup = 'Аналитика';

    protected static ?string $navigationLabel = 'Запланированные сообщения';

    protected static ?string $modelLabel = 'Запланированное сообщение';

    protected static ?string $pluralModelLabel = 'Запланированные сообщения';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->label('Название')
                ->required()
                ->maxLength(255),

            TextInput::make('control_type')
                ->label('Тип сообщения')
                ->helperText('Стабильный идентификатор, например control_question, reminder или information.')
                ->required()
                ->rules(['regex:/^[a-z][a-z0-9_]*$/'])
                ->maxLength(100),

            Select::make('telegram_chat_record_id')
                ->label('Telegram чат')
                ->options(fn (?TelegramScheduledMessage $record): array => app(TelegramDestinationCatalog::class)
                    ->chatOptions($record?->telegram_chat_record_id))
                ->searchable()
                ->native(false)
                ->live()
                ->afterStateUpdated(fn (Set $set): mixed => $set('telegram_topic_record_id', null))
                ->required(),

            Select::make('telegram_topic_record_id')
                ->label('Telegram тема / топик')
                ->options(fn (Get $get, ?TelegramScheduledMessage $record): array => app(TelegramDestinationCatalog::class)
                    ->topicOptions(
                        filled($get('telegram_chat_record_id')) ? (int) $get('telegram_chat_record_id') : null,
                        $record !== null && (int) $record->telegram_chat_record_id === (int) $get('telegram_chat_record_id')
                            ? (int) $record->telegram_topic_record_id
                            : null,
                    ))
                ->searchable()
                ->native(false)
                ->placeholder('Весь чат, без темы')
                ->visible(fn (Get $get): bool => filled($get('telegram_chat_record_id')))
                ->nullable(),

            Textarea::make('message')
                ->label('Текст сообщения')
                ->required()
                ->rows(5)
                ->maxLength(4000)
                ->columnSpanFull(),

            TimePicker::make('send_time')
                ->label('Время отправки')
                ->seconds(false)
                ->required(),

            Select::make('weekdays')
                ->label('Дни недели')
                ->options([
                    1 => 'Понедельник',
                    2 => 'Вторник',
                    3 => 'Среда',
                    4 => 'Четверг',
                    5 => 'Пятница',
                    6 => 'Суббота',
                    7 => 'Воскресенье',
                ])
                ->multiple()
                ->required()
                ->native(false),

            Toggle::make('enabled')
                ->label('Активно')
                ->default(true),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->with(['telegramChat', 'telegramTopic'])
                ->withMax([
                    'deliveries as last_successful_sent_at' => fn (Builder $deliveries): Builder => $deliveries->where('status', 'sent'),
                ], 'sent_at'))
            ->columns([
                TextColumn::make('name')
                    ->label('Название')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('control_type')
                    ->label('Тип сообщения')
                    ->searchable(),

                TextColumn::make('telegram_chat')
                    ->label('Чат')
                    ->state(fn (TelegramScheduledMessage $record): string => $record->telegramChat?->title ?: 'Чат недоступен'),

                TextColumn::make('telegram_topic')
                    ->label('Тема')
                    ->state(fn (TelegramScheduledMessage $record): string => $record->telegramTopic?->title
                        ?: ($record->telegramTopic ? 'Тема #'.$record->telegramTopic->telegram_thread_id : 'Весь чат')),

                TextColumn::make('send_time')
                    ->label('Время')
                    ->formatStateUsing(fn (?string $state): string => substr((string) $state, 0, 5))
                    ->sortable(),

                TextColumn::make('weekdays')
                    ->label('Дни')
                    ->state(fn (TelegramScheduledMessage $record): string => $record->weekdaysLabel()),

                IconColumn::make('enabled')
                    ->label('Активно')
                    ->boolean(),

                TextColumn::make('last_successful_sent_at')
                    ->label('Последняя отправка')
                    ->dateTime('d.m.Y H:i')
                    ->placeholder('—')
                    ->sortable(),
            ])
            ->filters([
                TernaryFilter::make('enabled')
                    ->label('Активность')
                    ->trueLabel('Активные')
                    ->falseLabel('Отключённые'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function getRelations(): array
    {
        return [\App\Filament\Resources\TelegramScheduledMessages\RelationManagers\DeliveriesRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTelegramScheduledMessages::route('/'),
            'create' => CreateTelegramScheduledMessage::route('/create'),
            'edit' => EditTelegramScheduledMessage::route('/{record}/edit'),
        ];
    }
}
