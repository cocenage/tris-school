<?php

namespace App\Filament\Resources\TelegramScheduledMessages\RelationManagers;

use App\Models\TelegramScheduledMessageDelivery;
use App\Services\Telegram\TelegramScheduledControlStatistics;
use App\Services\Telegram\TelegramScheduledControlSummaryBuilder;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class DeliveriesRelationManager extends RelationManager
{
    protected static string $relationship = 'deliveries';

    protected static ?string $title = 'Отправки и ответы';

    public function table(Table $table): Table
    {
        return $table->modifyQueryUsing(fn (Builder $query) => $query->with('responses'))
            ->defaultSort('scheduled_for', 'desc')
            ->columns([
                TextColumn::make('scheduled_for')->label('План')->dateTime('d.m.Y H:i')->timezone('Europe/Rome')->sortable(),
                TextColumn::make('sent_at')->label('Отправлено')->dateTime('d.m.Y H:i')->timezone('Europe/Rome'),
                TextColumn::make('status')->label('Доставка')->badge(),
                TextColumn::make('telegram_message_id')->label('Telegram сообщение'),
                TextColumn::make('result')->label('Итог')->state(fn ($record) => $this->stats($record)['result'])->badge(),
                TextColumn::make('response_count')->label('Ответов')->state(fn ($record) => $this->stats($record)['response_count']),
                TextColumn::make('responders')->label('Сотрудников')->state(fn ($record) => $this->stats($record)['unique_responder_count']),
                TextColumn::make('first_response')->label('Первый ответ, сек')->state(fn ($record) => $this->stats($record)['first_response_latency_seconds']),
                TextColumn::make('median_response')->label('Медиана, сек')->state(fn ($record) => $this->stats($record)['median_response_latency_seconds']),
            ])->filters([
                Filter::make('period')->schema([DatePicker::make('from')->label('С'), DatePicker::make('until')->label('По')])
                    ->query(function (Builder $query, array $data): Builder {
                        $builder = app(TelegramScheduledControlSummaryBuilder::class);
                        $timezone = config('app.timezone', 'Europe/Rome');
                        if (filled($data['from'] ?? null)) {
                            $query->where('scheduled_for', '>=', $builder->day($data['from'])->setTimezone($timezone)->format('Y-m-d H:i:s'));
                        }
                        if (filled($data['until'] ?? null)) {
                            $query->where('scheduled_for', '<', $builder->day($data['until'])->addDay()->setTimezone($timezone)->format('Y-m-d H:i:s'));
                        }

                        return $query;
                    }),
            ])->headerActions([
                Action::make('statistics')->label('Статистика за 30 дней')->modalSubmitAction(false)
                    ->schema(function (): array {
                        $now = CarbonImmutable::now('Europe/Rome');
                        $summary = app(TelegramScheduledControlSummaryBuilder::class)->build($now->subDays(29)->toDateString(), (int) $this->getOwnerRecord()->id, throughDate: $now->toDateString());
                        $totals = $summary['totals'];

                        return collect(['controls' => 'Отправок', 'responded' => 'С ответом', 'no_response' => 'Без ответа', 'problem' => 'Проблемы', 'partial' => 'Частично', 'confirmed' => 'Подтверждено', 'unclear' => 'Неясно', 'not_delivered' => 'Не доставлено', 'median_response_latency_seconds' => 'Медиана ответа, сек'])
                            ->map(fn ($label, $key) => TextEntry::make($key)->label($label)->state($totals[$key]))->values()->all();
                    }),
            ])->recordActions([
                Action::make('responses')->label('Ответы')->modalSubmitAction(false)
                    ->schema(fn (TelegramScheduledMessageDelivery $record): array => [
                        TextEntry::make('control')->label('Сообщение')->state($this->getOwnerRecord()->name),
                        TextEntry::make('planned')->label('План')->state($record->scheduled_for?->format('d.m.Y H:i')),
                        TextEntry::make('sent')->label('Отправлено')->state($record->sent_at?->format('d.m.Y H:i')),
                        TextEntry::make('telegram_id')->label('Telegram сообщение')->state($record->telegram_message_id),
                        TextEntry::make('final_result')->label('Итог')->state($this->stats($record)['result']),
                        RepeatableEntry::make('raw_responses')->label('Исходные ответы')
                            ->state($record->responses->map(fn ($response) => $response->only(['author_name', 'username', 'telegram_user_id', 'text', 'responded_at', 'classification', 'classification_reason', 'response_latency_seconds']))->all())
                            ->schema([
                                TextEntry::make('author_name')->label('Сотрудник'),
                                TextEntry::make('telegram_user_id')->label('Telegram автор'),
                                TextEntry::make('username')->label('Username'),
                                TextEntry::make('responded_at')->label('Время')->dateTime('d.m.Y H:i:s')->timezone('Europe/Rome'),
                                TextEntry::make('text')->label('Ответ')->columnSpanFull(),
                                TextEntry::make('classification')->label('Классификация'),
                                TextEntry::make('classification_reason')->label('Причина'),
                                TextEntry::make('response_latency_seconds')->label('Ответ через, сек'),
                            ])->columns(2),
                    ]),
            ]);
    }

    private function stats(TelegramScheduledMessageDelivery $record): array
    {
        return app(TelegramScheduledControlStatistics::class)->delivery($record);
    }
}
