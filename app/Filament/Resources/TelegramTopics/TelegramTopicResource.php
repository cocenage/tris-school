<?php

namespace App\Filament\Resources\TelegramTopics;

use App\Filament\Resources\TelegramTopics\Pages\EditTelegramTopic;
use App\Filament\Resources\TelegramTopics\Pages\ListTelegramTopics;
use App\Models\TelegramTopic;
use App\Services\Telegram\TelegramTopicPresenter;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\SelectColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
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

            Select::make('apartment_id')
                ->label('Квартира')
                ->options(fn (): array => app(TelegramTopicPresenter::class)->apartmentOptions())
                ->searchable()
                ->placeholder('Без квартиры (дежурный / служебный topic)')
                ->helperText(fn (?TelegramTopic $record): ?string => $record && app(TelegramTopicPresenter::class)->isServiceTopic($record)
                    ? 'Это служебный topic: квартира не требуется. Если mapping уже есть, его можно очистить вручную.'
                    : null)
                ->nullable(),

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
                    'mobility' => 'Транспорт',
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
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with([
                'chat',
                'apartment',
                'recentMessages',
            ]))
            ->groups([
                Group::make('telegram_chat_id')
                    ->label('Район / Telegram chat')
                    ->getTitleFromRecordUsing(fn (TelegramTopic $record): string => app(TelegramTopicPresenter::class)->chatLabel($record->chat))
                    ->collapsible(),
            ])
            ->defaultGroup('telegram_chat_id')
            ->defaultSort('telegram_thread_id')
            ->columns([
                TextColumn::make('chat.title')
                    ->label('Район / Telegram chat')
                    ->formatStateUsing(fn (TelegramTopic $record): string => app(TelegramTopicPresenter::class)->chatLabel($record->chat))
                    ->searchable()
                    ->placeholder('—'),

                TextColumn::make('title')
                    ->label('Topic')
                    ->state(fn (TelegramTopic $record): string => app(TelegramTopicPresenter::class)->title($record))
                    ->description(fn (TelegramTopic $record): ?string => app(TelegramTopicPresenter::class)->hasHumanTitle($record)
                        ? null
                        : 'Название Telegram не сохранено')
                    ->searchable()
                    ->placeholder('Не подписан'),

                TextColumn::make('context_preview')
                    ->label('Контекст последних сообщений')
                    ->state(fn (TelegramTopic $record): ?string => app(TelegramTopicPresenter::class)->contextPreview($record))
                    ->placeholder('Контекст не найден')
                    ->wrap()
                    ->limit(230),

                TextColumn::make('telegram_thread_id')
                    ->label('Thread ID')
                    ->searchable()
                    ->sortable(),

                SelectColumn::make('apartment_id')
                    ->label('Квартира')
                    ->options(fn (): array => app(TelegramTopicPresenter::class)->apartmentOptions())
                    ->searchableOptions()
                    ->native(false)
                    ->placeholder('Без квартиры'),

                TextColumn::make('topic_role')
                    ->label('Роль topic')
                    ->state(fn (TelegramTopic $record): string => app(TelegramTopicPresenter::class)->roleLabel($record))
                    ->badge()
                    ->color(fn (TelegramTopic $record): string => app(TelegramTopicPresenter::class)->isServiceTopic($record) ? 'info' : 'gray'),

                TextColumn::make('mapping_status')
                    ->label('Mapping')
                    ->state(fn (TelegramTopic $record): string => app(TelegramTopicPresenter::class)->mappingLabel($record))
                    ->badge()
                    ->color(fn (TelegramTopic $record): string => app(TelegramTopicPresenter::class)->mappingColor($record)),

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
                        'mobility' => 'Транспорт',
                        'other' => 'Другое',
                        default => '—',
                    })
                    ->toggleable(isToggledHiddenByDefault: true),

                IconColumn::make('is_enabled')
                    ->label('Активен')
                    ->boolean(),
            ])
            ->filters([
                SelectFilter::make('telegram_chat_id')
                    ->label('Район / Telegram chat')
                    ->options(fn (): array => app(TelegramTopicPresenter::class)->chatOptions())
                    ->searchable(),

                TernaryFilter::make('without_apartment')
                    ->label('Без квартиры')
                    ->placeholder('Все topics')
                    ->trueLabel('Без квартиры')
                    ->falseLabel('С квартирой')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereNull('apartment_id'),
                        false: fn (Builder $query): Builder => $query->whereNotNull('apartment_id'),
                        blank: fn (Builder $query): Builder => $query,
                    ),

                TernaryFilter::make('unmapped_apartment_candidates')
                    ->label('Квартирные без привязки')
                    ->placeholder('Все topics')
                    ->trueLabel('Квартирные без привязки')
                    ->falseLabel('С квартирой')
                    ->queries(
                        true: fn (Builder $query): Builder => app(TelegramTopicPresenter::class)
                            ->scopeUnmappedApartmentCandidates($query),
                        false: fn (Builder $query): Builder => $query->whereNotNull('apartment_id'),
                        blank: fn (Builder $query): Builder => $query,
                    ),
            ])
            ->recordActions([
                Action::make('open_telegram')
                    ->label('Открыть в Telegram')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn (TelegramTopic $record): ?string => app(TelegramTopicPresenter::class)->telegramUrl($record))
                    ->openUrlInNewTab()
                    ->visible(fn (TelegramTopic $record): bool => app(TelegramTopicPresenter::class)->telegramUrl($record) !== null),
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
