<?php

namespace App\Filament\Resources\TelegramChats\RelationManagers;

use App\Filament\Resources\TelegramTopics\TelegramTopicResource;
use App\Models\TelegramTopic;
use App\Services\Telegram\TelegramTopicPresenter;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class TopicsRelationManager extends RelationManager
{
    protected static string $relationship = 'topics';

    protected static ?string $title = 'Темы этого форума';

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->with(['apartment', 'chat'])
                ->withMax('messages as last_activity_at', 'sent_at'))
            ->defaultSort('telegram_thread_id')
            ->columns([
                TextColumn::make('title')
                    ->label('Название Telegram')
                    ->state(fn (TelegramTopic $record): string => app(TelegramTopicPresenter::class)->title($record))
                    ->description(fn (TelegramTopic $record): ?string => app(TelegramTopicPresenter::class)->hasHumanTitle($record)
                        ? null
                        : app(TelegramTopicPresenter::class)->contextPreview($record))
                    ->searchable()
                    ->wrap(),

                TextColumn::make('telegram_thread_id')->label('Thread ID')->searchable()->sortable(),

                TextColumn::make('apartment.name')
                    ->label('Привязанная квартира')
                    ->placeholder('Не назначена'),

                TextColumn::make('topic_role')
                    ->label('Тип темы')
                    ->state(fn (TelegramTopic $record): string => app(TelegramTopicPresenter::class)->roleLabel($record))
                    ->badge(),

                TextColumn::make('purpose')->label('Назначение')->placeholder('Обычная тема')->badge(),

                IconColumn::make('is_enabled')->label('В TRIS включена')->boolean(),

                TextColumn::make('destination_status')
                    ->label('Адресат отправки')
                    ->state(fn (TelegramTopic $record): string => app(TelegramTopicPresenter::class)->destinationStatus($record))
                    ->wrap(),

                TextColumn::make('last_activity_at')
                    ->label('Последняя активность')
                    ->dateTime('d.m.Y H:i')
                    ->placeholder('Нет сообщений')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('apartment_id')
                    ->label('Квартира')
                    ->options(fn (): array => app(TelegramTopicPresenter::class)->apartmentOptions()),

                TernaryFilter::make('is_enabled')->label('Статус в TRIS')->trueLabel('Включена')->falseLabel('Отключена'),

                SelectFilter::make('topic_role')
                    ->label('Тип темы')
                    ->options(['apartment' => 'Квартирная', 'service' => 'Служебная / дежурная'])
                    ->query(function (Builder $query, array $data): Builder {
                        $presenter = app(TelegramTopicPresenter::class);

                        return match ($data['value'] ?? null) {
                            'service' => $presenter->scopeServiceTopics($query),
                            'apartment' => $presenter->scopeApartmentTopics($query),
                            default => $query,
                        };
                    }),
            ])
            ->recordActions([
                Action::make('edit_mapping')
                    ->label('Изменить привязку')
                    ->url(fn (TelegramTopic $record): string => TelegramTopicResource::getUrl('edit', ['record' => $record])),
            ]);
    }
}
