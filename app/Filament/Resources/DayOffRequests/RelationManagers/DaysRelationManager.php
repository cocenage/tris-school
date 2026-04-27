<?php

namespace App\Filament\Resources\DayOffRequests\RelationManagers;

use App\Models\DayOffRequestDay;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class DaysRelationManager extends RelationManager
{
    protected static string $relationship = 'days';

    protected static ?string $title = 'Даты заявки';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Textarea::make('admin_comment')
                    ->label('Комментарий администратора')
                    ->rows(3)
                    ->columnSpanFull(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('date')
            ->columns([
                TextColumn::make('date')
                    ->label('Дата')
                    ->date('d.m.Y')
                    ->weight('medium')
                    ->sortable(),

                TextColumn::make('status')
                    ->label('Статус')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => self::statusLabel($state))
                    ->color(fn (?string $state): string => self::statusColor($state))
                    ->sortable(),

                TextColumn::make('admin_comment')
                    ->label('Комментарий')
                    ->placeholder('—')
                    ->limit(70)
                    ->wrap(),

                TextColumn::make('reviewed_at')
                    ->label('Рассмотрено')
                    ->dateTime('d.m.Y H:i')
                    ->placeholder('—')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->headerActions([])
            ->actions([
                Action::make('approve')
                    ->label('Одобрить')
                    ->icon('heroicon-m-check')
                    ->color('success')
                    ->visible(fn (DayOffRequestDay $record): bool => $record->status !== 'approved')
                    ->requiresConfirmation()
                    ->action(function (DayOffRequestDay $record): void {
                        $request = $record->request;

                        if ($record->status !== 'approved') {
                            $request->resetNotification();
                        }

                        $record->update([
                            'status' => 'approved',
                            'admin_comment' => null,
                            'reviewed_at' => now(),
                            'reviewed_by' => auth()->id(),
                        ]);

                        $request->syncStatusAndNotify();
                    }),

                Action::make('reject')
                    ->label('Отклонить')
                    ->icon('heroicon-m-x-mark')
                    ->color('danger')
                    ->visible(fn (DayOffRequestDay $record): bool => $record->status !== 'rejected')
                    ->requiresConfirmation()
                    ->schema([
                        Textarea::make('admin_comment')
                            ->label('Причина отказа')
                            ->rows(3)
                            ->default(fn (DayOffRequestDay $record) => $record->admin_comment),
                    ])
                    ->action(function (DayOffRequestDay $record, array $data): void {
                        $request = $record->request;

                        $adminComment = filled($data['admin_comment'] ?? null)
                            ? trim((string) $data['admin_comment'])
                            : null;

                        $changed =
                            $record->status !== 'rejected'
                            || $record->admin_comment !== $adminComment;

                        if ($changed) {
                            $request->resetNotification();
                        }

                        $record->update([
                            'status' => 'rejected',
                            'admin_comment' => $adminComment,
                            'reviewed_at' => now(),
                            'reviewed_by' => auth()->id(),
                        ]);

                        $request->syncStatusAndNotify();
                    }),

                Action::make('reset')
                    ->label('Сбросить')
                    ->icon('heroicon-m-arrow-path')
                    ->color('gray')
                    ->visible(fn (DayOffRequestDay $record): bool => $record->status !== 'pending')
                    ->requiresConfirmation()
                    ->action(function (DayOffRequestDay $record): void {
                        $request = $record->request;

                        $record->update([
                            'status' => 'pending',
                            'admin_comment' => null,
                            'reviewed_at' => null,
                            'reviewed_by' => null,
                        ]);

                        $request->resetNotification();
                        $request->recalculateStatus();
                    }),
            ]);
    }

    protected static function statusLabel(?string $status): string
    {
        return match ($status) {
            'approved' => 'Одобрено',
            'rejected' => 'Отклонено',
            default => 'На рассмотрении',
        };
    }

    protected static function statusColor(?string $status): string
    {
        return match ($status) {
            'approved' => 'success',
            'rejected' => 'danger',
            default => 'info',
        };
    }
}