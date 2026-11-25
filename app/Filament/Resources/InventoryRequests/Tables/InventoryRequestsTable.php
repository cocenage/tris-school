<?php

namespace App\Filament\Resources\InventoryRequests\Tables;

use App\Filament\Resources\InventoryRequests\InventoryRequestResource;
use App\Models\InventoryRequest;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class InventoryRequestsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['user', 'lines']))
            ->recordTitleAttribute('user_name')
            ->recordUrl(fn ($record) => InventoryRequestResource::getUrl($record->telegram_review_version !== null ? 'view' : 'edit', ['record' => $record]))
            ->defaultSort('created_at', 'desc')

            ->columns([
                TextColumn::make('user.name')
                    ->label('Сотрудник')
                    ->searchable()
                    ->sortable()
                    ->weight('medium')
                    ->description(fn (InventoryRequest $record): string => $record->created_at?->format('d.m.Y H:i') ?? '—'),

                TextColumn::make('items')
                    ->label('Позиции')
                    ->state(function (InventoryRequest $record): string {
                        if ($record->lines->isEmpty()) {
                            return '—';
                        }

                        return $record->lines
                            ->map(function ($line) {
                                $name = $line->item_name;

                                if ($line->variant_label) {
                                    $name .= ' (' . $line->variant_label . ')';
                                }

                                return $name . ' × ' . $line->requested_qty;
                            })
                            ->implode(', ');
                    })
                    ->limit(90)
                    ->wrap(),

                // TextColumn::make('lines_count')
                //     ->label('Позиций')
                //     ->state(fn (InventoryRequest $record): int => $record->lines->count())
                //     ->badge()
                //     ->color('gray'),

                TextColumn::make('status')
                    ->label('Статус')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => self::statusLabel($state))
                    ->color(fn (?string $state): string => self::statusColor($state))
                    ->sortable(),

                TextColumn::make('comment')
                    ->label('Комментарий')
                    ->limit(50)
                    ->wrap()
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('admin_comment')
                    ->label('Комментарий администратора')
                    ->limit(50)
                    ->placeholder('—')
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('processed_at')
                    ->label('Обработано')
                    ->dateTime('d.m.Y H:i')
                    ->placeholder('—')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('created_at')
                    ->label('Создано')
                    ->dateTime('d.m.Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])

            ->filters([
                SelectFilter::make('status')
                    ->label('Статус заявки')
                    ->options([
                        'pending' => 'На рассмотрении',
                        'approved' => 'Одобрено',
            'partially_approved' => 'Частично одобрено',
            'rejected' => 'Отказано',
            'issued' => 'Выдано',
                        'partially_issued' => 'Выдано частично',
                        'cancelled' => 'Не выдано',
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
                    Action::make('set_admin_comment')
                        ->visible(fn (InventoryRequest $record): bool => $record->telegram_review_version === null)
                        ->label('Комментарий администратора')
                        ->icon('heroicon-m-chat-bubble-left-right')
                        ->color('gray')
                        ->schema([
                            Textarea::make('admin_comment')
                                ->label('Комментарий администратора')
                                ->rows(4)
                                ->default(fn (InventoryRequest $record) => $record->admin_comment),
                        ])
                        ->action(function (InventoryRequest $record, array $data): void {
                            abort_unless($record->fresh()?->telegram_review_version === null, 409, 'Используйте общую форму рассмотрения.');
                            $record->update([
                                'admin_comment' => filled($data['admin_comment'] ?? null)
                                    ? trim((string) $data['admin_comment'])
                                    : null,
                            ]);
                        }),

                    Action::make('compact_review')
                        ->label('Рассмотреть заявку')
                        ->visible(fn (InventoryRequest $record): bool => $record->telegram_review_version !== null && (int) $record->telegram_review_version === 0 && auth()->user()?->isAdmin())
                        ->url(fn (InventoryRequest $record): string => route('structured.admin.show', ['inventory', $record->id])),

                    ViewAction::make()
                        ->label('Открыть'),

                    EditAction::make()
                        ->visible(fn (InventoryRequest $record): bool => $record->telegram_review_version === null)
                        ->label('Обработать'),
                ]),
            ])

            ->bulkActions([]);
    }

    protected static function statusLabel(?string $status): string
    {
        return match ($status) {
            'approved' => 'Одобрено',
            'partially_approved' => 'Частично одобрено',
            'rejected' => 'Отказано',
            'issued' => 'Выдано',
            'partially_issued' => 'Выдано частично',
            'cancelled' => 'Не выдано',
            default => 'На рассмотрении',
        };
    }

    protected static function statusColor(?string $status): string
    {
        return match ($status) {
            'approved', 'issued' => 'success',
            'partially_approved', 'partially_issued' => 'warning',
            'rejected', 'cancelled' => 'danger',
            default => 'info',
        };
    }
}