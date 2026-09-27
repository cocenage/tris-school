<?php

namespace App\Filament\Resources\TelegramChats;

use App\Filament\Resources\TelegramChats\Pages\ListTelegramChats;
use App\Filament\Resources\TelegramChats\Pages\ViewTelegramChat;
use App\Filament\Resources\TelegramChats\RelationManagers\TopicsRelationManager;
use App\Models\TelegramChat;
use App\Services\Telegram\TelegramTopicPresenter;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Resources\Resource;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class TelegramChatResource extends Resource
{
    protected static ?string $model = TelegramChat::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-chat-bubble-left-right';

    protected static string|UnitEnum|null $navigationGroup = 'Аналитика';

    protected static ?string $navigationLabel = 'Telegram форумы';

    protected static ?string $modelLabel = 'Telegram форум';

    protected static ?string $pluralModelLabel = 'Telegram форумы';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withCount([
                'topics',
                'topics as mapped_topics_count' => fn (Builder $topics): Builder => app(TelegramTopicPresenter::class)
                    ->scopeApartmentTopics($topics)
                    ->whereNotNull('apartment_id'),
                'topics as unmapped_topics_count' => fn (Builder $topics): Builder => app(TelegramTopicPresenter::class)
                    ->scopeApartmentTopics($topics)
                    ->whereNull('apartment_id'),
            ])
            ->withMax('messages as last_activity_at', 'sent_at');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('title')
            ->columns([
                TextColumn::make('district')
                    ->label('Район / форум')
                    ->state(fn (TelegramChat $record): string => app(TelegramTopicPresenter::class)->chatLabel($record))
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where(function (Builder $chat) use ($search): void {
                        $chat->where('title', 'like', '%'.$search.'%')
                            ->orWhere('telegram_chat_id', 'like', '%'.$search.'%');
                    }))
                    ->wrap(),

                TextColumn::make('telegram_chat_id')
                    ->label('Telegram chat ID')
                    ->searchable()
                    ->copyable(),

                TextColumn::make('type')
                    ->label('Тип')
                    ->placeholder('—')
                    ->badge(),

                IconColumn::make('is_enabled')
                    ->label('В TRIS включён')
                    ->boolean(),

                TextColumn::make('topics_count')->label('Тем')->sortable(),
                TextColumn::make('mapped_topics_count')->label('С квартирой')->sortable(),
                TextColumn::make('unmapped_topics_count')->label('Без квартиры')->sortable(),

                TextColumn::make('last_activity_at')
                    ->label('Последняя активность')
                    ->dateTime('d.m.Y H:i')
                    ->placeholder('Нет сообщений')
                    ->sortable(),

                TextColumn::make('bot_access')
                    ->label('AcademyBot')
                    ->state('Доступ не проверен')
                    ->tooltip('Локальные записи не подтверждают членство бота; безопасная проверка без отправки недоступна.'),
            ])
            ->filters([
                TernaryFilter::make('is_enabled')->label('Статус в TRIS')->trueLabel('Включён')->falseLabel('Отключён'),
            ])
            ->recordActions([ViewAction::make()->label('Форум и темы')]);
    }

    public static function getRelations(): array
    {
        return [TopicsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTelegramChats::route('/'),
            'view' => ViewTelegramChat::route('/{record}'),
        ];
    }
}
