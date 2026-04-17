<?php

namespace App\Filament\Resources\InventoryRequests\Tables;

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
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['user', 'lines']))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('user.name')
                    ->label('Сотрудник')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('items')
                    ->label('Позиции')
                    ->state(function (InventoryRequest $record): string {
                        return $record->lines
                            ->map(function ($line) {
                                $name = $line->item_name;

                                if ($line->variant_label) {
                                    $name .= ' (' . $line->variant_label . ')';
                                }

                                return $name . ' — ' . $line->requested_qty;
                            })
                            ->implode(', ');
                    })
                    ->wrap(),

                TextColumn::make('comment')
                    ->label('Комментарий')
                    ->limit(60)
                    ->wrap()
                    ->placeholder('—'),

                TextColumn::make('admin_comment')
                    ->label('Комментарий администратора')
                    ->limit(40)
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->wrap(),

                TextColumn::make('status')
                    ->label('Статус')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'issued' => 'Выдано',
                        'partially_issued' => 'Выдано частично',
                        'cancelled' => 'Не выдано',
                        default => 'На рассмотрении',
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'issued' => 'success',
                        'partially_issued' => 'warning',
                        'cancelled' => 'danger',
                        default => 'info',
                    })
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label('Создано')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Статус заявки')
                    ->options([
                        'pending' => 'На рассмотрении',
                        'issued' => 'Выдано',
                        'partially_issued' => 'Выдано частично',
                        'cancelled' => 'Не выдано',
                    ]),

                SelectFilter::make('user_id')
                    ->label('Сотрудник')
                    ->relationship('user', 'name')
                    ->searchable()
                    ->preload(),
            ])
            ->recordActions([
                ActionGroup::make([
                    Action::make('set_admin_comment')
                        ->label('Комментарий администратора')
                        ->icon('heroicon-o-chat-bubble-left-right')
                        ->color('gray')
                        ->schema([
                            Textarea::make('admin_comment')
                                ->label('Комментарий администратора')
                                ->rows(4)
                                ->default(fn (InventoryRequest $record) => $record->admin_comment),
                        ])
                        ->action(function (InventoryRequest $record, array $data): void {
                            $record->update([
                                'admin_comment' => filled($data['admin_comment'] ?? null)
                                    ? trim((string) $data['admin_comment'])
                                    : null,
                            ]);
                        }),

                    ViewAction::make(),
                    EditAction::make(),
                ]),
            ]);
    }
}