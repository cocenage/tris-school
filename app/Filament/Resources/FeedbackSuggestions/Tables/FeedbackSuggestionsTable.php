<?php

namespace App\Filament\Resources\FeedbackSuggestions\Tables;

use App\Filament\Resources\FeedbackSuggestions\FeedbackSuggestionResource;
use App\Models\FeedbackSuggestion;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class FeedbackSuggestionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('user'))
            ->recordTitleAttribute('user_name')
            ->recordUrl(fn ($record) => FeedbackSuggestionResource::getUrl('edit', ['record' => $record]))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('user.name')
                    ->label('Сотрудник')
                    ->searchable()
                    ->sortable()
                    ->weight('medium')
                    ->description(fn (FeedbackSuggestion $record): string => $record->created_at?->format('d.m.Y H:i') ?? '—'),

                TextColumn::make('type')
                    ->label('Тип')
                    ->badge()
                    ->color('gray')
                    ->searchable(),

                TextColumn::make('status')
                    ->label('Статус')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => self::statusLabel($state))
                    ->color(fn (?string $state): string => self::statusColor($state))
                    ->sortable(),

                TextColumn::make('comment')
                    ->label('Сообщение')
                    ->limit(70)
                    ->wrap()
                    ->placeholder('—'),

                TextColumn::make('admin_comment')
                    ->label('Комментарий администратора')
                    ->limit(50)
                    ->wrap()
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('created_at')
                    ->label('Создано')
                    ->dateTime('d.m.Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Статус')
                    ->options([
                        'pending' => 'На рассмотрении',
                        'reviewed' => 'Рассмотрено',
                        'closed' => 'Закрыто',
                    ])
                    ->placeholder('Все'),

                SelectFilter::make('user_id')
                    ->label('Сотрудник')
                    ->relationship('user', 'name')
                    ->searchable()
                    ->preload(),
            ])
            ->actions([
                ActionGroup::make([
                    Action::make('mark_reviewed')
                        ->label('Рассмотрено')
                        ->icon('heroicon-m-eye')
                        ->color('info')
                        ->visible(fn (FeedbackSuggestion $record): bool => $record->status !== 'reviewed')
                        ->requiresConfirmation()
                        ->action(fn (FeedbackSuggestion $record) => $record->update([
                            'status' => 'reviewed',
                        ])),

                    Action::make('close')
                        ->label('Закрыть')
                        ->icon('heroicon-m-check-circle')
                        ->color('success')
                        ->visible(fn (FeedbackSuggestion $record): bool => $record->status !== 'closed')
                        ->requiresConfirmation()
                        ->action(fn (FeedbackSuggestion $record) => $record->update([
                            'status' => 'closed',
                        ])),

                    Action::make('set_admin_comment')
                        ->label('Комментарий администратора')
                        ->icon('heroicon-m-chat-bubble-left-right')
                        ->color('gray')
                        ->schema([
                            Textarea::make('admin_comment')
                                ->label('Комментарий администратора')
                                ->rows(4)
                                ->default(fn (FeedbackSuggestion $record) => $record->admin_comment),
                        ])
                        ->action(function (FeedbackSuggestion $record, array $data): void {
                            $record->update([
                                'admin_comment' => filled($data['admin_comment'] ?? null)
                                    ? trim((string) $data['admin_comment'])
                                    : null,
                            ]);
                        }),

                    ViewAction::make()
                        ->label('Открыть'),

                    EditAction::make()
                        ->label('Обработать'),
                ]),
            ])
            ->bulkActions([]);
    }

    protected static function statusLabel(?string $status): string
    {
        return match ($status) {
            'reviewed' => 'Рассмотрено',
            'closed' => 'Закрыто',
            default => 'На рассмотрении',
        };
    }

    protected static function statusColor(?string $status): string
    {
        return match ($status) {
            'reviewed' => 'info',
            'closed' => 'success',
            default => 'warning',
        };
    }
}