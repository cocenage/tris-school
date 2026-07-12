<?php

namespace App\Filament\Resources\MobilityAlerts\Tables;

use App\Models\MobilityAlert;
use App\Models\MobilityAlertMessage;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MobilityAlertsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('starts_at')
                    ->label('Дата')
                    ->date('d.m.Y')
                    ->sortable(),

                TextColumn::make('risk')
                    ->label('Риск')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'critical' => 'danger',
                        'high' => 'warning',
                        'medium' => 'info',
                        default => 'gray',
                    })
                    ->sortable(),

                TextColumn::make('type')
                    ->label('Тип')
                    ->badge()
                    ->searchable(),

                TextColumn::make('source')
                    ->label('Источник')
                    ->badge()
                    ->searchable(),

                TextColumn::make('title')
                    ->label('Заголовок')
                    ->searchable()
                    ->limit(80),

                TextColumn::make('district')
                    ->label('Район / линия')
                    ->searchable()
                    ->toggleable(),

                TextColumn::make('messages_count')
                    ->label('TG')
                    ->counts('messages')
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label('Создано')
                    ->dateTime('d.m.Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('updated_at')
                    ->label('Обновлено')
                    ->dateTime('d.m.Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('starts_at', 'desc')
            ->filters([
                SelectFilter::make('risk')
                    ->label('Риск')
                    ->options([
                        'critical' => 'Критичный',
                        'high' => 'Высокий',
                        'medium' => 'Средний',
                        'low' => 'Низкий',
                    ]),

                SelectFilter::make('type')
                    ->label('Тип')
                    ->options([
                        'strike' => 'Забастовка',
                        'transport' => 'Транспорт',
                        'metro' => 'Метро',
                        'train' => 'Поезда',
                        'traffic' => 'Трафик',
                    ]),
            ])
            ->recordActions([
                EditAction::make(),

                Action::make('deleteTelegramMessages')
                    ->label('Удалить TG')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->action(fn (MobilityAlert $record) => self::deleteTelegramMessagesForAlert($record)),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),

                    BulkAction::make('deleteTelegramMessages')
                        ->label('Удалить TG')
                        ->icon('heroicon-o-trash')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->action(function (Collection $records): void {
                            foreach ($records as $record) {
                                self::deleteTelegramMessagesForAlert($record);
                            }
                        }),
                ]),
            ]);
    }

    protected static function deleteTelegramMessagesForAlert(MobilityAlert $alert): void
    {
        $token = config('services.telegram.analytics_bot_token');

        if (! $token) {
            return;
        }

        $messages = MobilityAlertMessage::query()
            ->where('mobility_alert_id', $alert->id)
            ->whereNull('deleted_at')
            ->get();

        foreach ($messages as $message) {
            try {
                $response = Http::timeout(30)
                    ->retry(3, 2000)
                    ->withoutVerifying()
                    ->post("https://api.telegram.org/bot{$token}/deleteMessage", [
                        'chat_id' => $message->chat_id,
                        'message_id' => $message->telegram_message_id,
                    ]);
            } catch (\Throwable $e) {
                Log::warning('Filament mobility message delete connection failed', [
                    'message_id' => $message->id,
                    'alert_id' => $alert->id,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            if (! $response->successful()) {
                Log::warning('Filament mobility message delete failed', [
                    'message_id' => $message->id,
                    'alert_id' => $alert->id,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                continue;
            }

            $message->update([
                'deleted_at' => now(),
            ]);

            usleep(500000);
        }
    }
}