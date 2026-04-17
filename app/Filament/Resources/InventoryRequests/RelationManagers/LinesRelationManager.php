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
                    ->label('Товар'),

                TextColumn::make('variant_label')
                    ->label('Вариант')
                    ->placeholder('—'),

                TextColumn::make('requested_qty')
                    ->label('Запрошено'),

                TextColumn::make('issued_qty')
                    ->label('Выдано'),

                TextColumn::make('status')
                    ->label('Статус')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'issued' => 'Выдано',
                        'partially_issued' => 'Частично',
                        'cancelled' => 'Не выдано',
                        default => 'На рассмотрении',
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'issued' => 'success',
                        'partially_issued' => 'warning',
                        'cancelled' => 'danger',
                        default => 'info',
                    }),

                TextColumn::make('admin_comment')
                    ->label('Комментарий')
                    ->placeholder('—')
                    ->wrap(),
            ])
            ->headerActions([])
            ->recordActions([
                Action::make('issue')
                    ->label('Выдать')
                    ->icon('heroicon-o-check')
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

                        $status = match (true) {
                            $issuedQty <= 0 => 'cancelled',
                            $issuedQty >= $requestedQty => 'issued',
                            default => 'partially_issued',
                        };

                        $changed =
                            $record->status !== $status
                            || (int) $record->issued_qty !== min($issuedQty, $requestedQty)
                            || $record->admin_comment !== ($data['admin_comment'] ?? null);

                        if ($changed) {
                            $request->resetNotification();
                        }

                        $record->update([
                            'issued_qty' => min($issuedQty, $requestedQty),
                            'status' => $status,
                            'admin_comment' => filled($data['admin_comment'] ?? null)
                                ? trim((string) $data['admin_comment'])
                                : null,
                            'processed_at' => now(),
                            'processed_by' => auth()->id(),
                        ]);

                        $request->syncStatusAndNotify();
                    }),

                Action::make('cancel')
                    ->label('Не выдавать')
                    ->icon('heroicon-o-x-mark')
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

                        $changed =
                            $record->status !== 'cancelled'
                            || (int) $record->issued_qty !== 0
                            || $record->admin_comment !== ($data['admin_comment'] ?? null);

                        if ($changed) {
                            $request->resetNotification();
                        }

                        $record->update([
                            'issued_qty' => 0,
                            'status' => 'cancelled',
                            'admin_comment' => filled($data['admin_comment'] ?? null)
                                ? trim((string) $data['admin_comment'])
                                : null,
                            'processed_at' => now(),
                            'processed_by' => auth()->id(),
                        ]);

                        $request->syncStatusAndNotify();
                    }),

                Action::make('reset')
                    ->label('Сбросить')
                    ->icon('heroicon-o-arrow-path')
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
}