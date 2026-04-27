<?php

namespace App\Filament\Resources\InventoryRequests\RelationManagers;

use App\Models\InventoryRequestLine;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class LinesRelationManager extends RelationManager
{
    protected static string $relationship = 'lines';

    protected static ?string $title = 'Позиции заявки';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('issued_qty')
                    ->label('Выдано')
                    ->numeric()
                    ->minValue(0)
                    ->required(),

                Textarea::make('admin_comment')
                    ->label('Комментарий администратора')
                    ->rows(3)
                    ->columnSpanFull(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('id')
            ->columns([
                TextColumn::make('item_name')
                    ->label('Товар')
                    ->weight('medium')
                    ->searchable(),

                TextColumn::make('variant_label')
                    ->label('Вариант')
                    ->placeholder('—'),

                TextColumn::make('requested_qty')
                    ->label('Запрошено')
                    ->badge()
                    ->color('gray'),

                TextColumn::make('issued_qty')
                    ->label('Выдано')
                    ->badge()
                    ->color(fn ($state): string => (int) $state > 0 ? 'success' : 'gray'),

                TextColumn::make('status')
                    ->label('Статус')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => self::statusLabel($state))
                    ->color(fn (?string $state): string => self::statusColor($state)),

                TextColumn::make('admin_comment')
                    ->label('Комментарий')
                    ->placeholder('—')
                    ->limit(60)
                    ->wrap(),

                TextColumn::make('processed_at')
                    ->label('Обработано')
                    ->dateTime('d.m.Y H:i')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->headerActions([])
            ->actions([
                Action::make('issue')
                    ->label('Выдать')
                    ->icon('heroicon-m-check')
                    ->color('success')
                    ->requiresConfirmation()
                    ->schema([
                        TextInput::make('issued_qty')
                            ->label('Сколько выдали')
                            ->numeric()
                            ->minValue(0)
                            ->required()
                            ->default(fn (InventoryRequestLine $record) => $record->requested_qty),

                        Textarea::make('admin_comment')
                            ->label('Комментарий')
                            ->rows(3)
                            ->default(fn (InventoryRequestLine $record) => $record->admin_comment),
                    ])
                    ->action(function (InventoryRequestLine $record, array $data): void {
                        $request = $record->request;

                        $issuedQty = max(0, (int) ($data['issued_qty'] ?? 0));
                        $requestedQty = (int) $record->requested_qty;
                        $finalIssuedQty = min($issuedQty, $requestedQty);

                        $status = match (true) {
                            $finalIssuedQty <= 0 => 'cancelled',
                            $finalIssuedQty >= $requestedQty => 'issued',
                            default => 'partially_issued',
                        };

                        $adminComment = filled($data['admin_comment'] ?? null)
                            ? trim((string) $data['admin_comment'])
                            : null;

                        $changed =
                            $record->status !== $status
                            || (int) $record->issued_qty !== $finalIssuedQty
                            || $record->admin_comment !== $adminComment;

                        if ($changed) {
                            $request->resetNotification();
                        }

                        $record->update([
                            'issued_qty' => $finalIssuedQty,
                            'status' => $status,
                            'admin_comment' => $adminComment,
                            'processed_at' => now(),
                            'processed_by' => auth()->id(),
                        ]);

                        $request->syncStatusAndNotify();
                    }),

                Action::make('cancel')
                    ->label('Не выдавать')
                    ->icon('heroicon-m-x-mark')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->schema([
                        Textarea::make('admin_comment')
                            ->label('Причина')
                            ->rows(3)
                            ->default(fn (InventoryRequestLine $record) => $record->admin_comment),
                    ])
                    ->action(function (InventoryRequestLine $record, array $data): void {
                        $request = $record->request;

                        $adminComment = filled($data['admin_comment'] ?? null)
                            ? trim((string) $data['admin_comment'])
                            : null;

                        $changed =
                            $record->status !== 'cancelled'
                            || (int) $record->issued_qty !== 0
                            || $record->admin_comment !== $adminComment;

                        if ($changed) {
                            $request->resetNotification();
                        }

                        $record->update([
                            'issued_qty' => 0,
                            'status' => 'cancelled',
                            'admin_comment' => $adminComment,
                            'processed_at' => now(),
                            'processed_by' => auth()->id(),
                        ]);

                        $request->syncStatusAndNotify();
                    }),

                Action::make('reset')
                    ->label('Сбросить')
                    ->icon('heroicon-m-arrow-path')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->action(function (InventoryRequestLine $record): void {
                        $request = $record->request;

                        $record->update([
                            'issued_qty' => 0,
                            'status' => 'pending',
                            'admin_comment' => null,
                            'processed_at' => null,
                            'processed_by' => null,
                        ]);

                        $request->resetNotification();
                        $request->recalculateStatus();
                    }),
            ]);
    }

    protected static function statusLabel(?string $status): string
    {
        return match ($status) {
            'issued' => 'Выдано',
            'partially_issued' => 'Частично',
            'cancelled' => 'Не выдано',
            default => 'На рассмотрении',
        };
    }

    protected static function statusColor(?string $status): string
    {
        return match ($status) {
            'issued' => 'success',
            'partially_issued' => 'warning',
            'cancelled' => 'danger',
            default => 'info',
        };
    }
}