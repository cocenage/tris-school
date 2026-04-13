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
                    ->date('d.m.Y'),

                TextColumn::make('status')
                    ->label('Статус')
                    ->badge()
                    ->formatStateUsing(fn(string $state): string => match ($state) {
                        'approved' => 'Одобрено',
                        'rejected' => 'Отклонено',
                        default => 'На рассмотрении',
                    })
                    ->color(fn(string $state): string => match ($state) {
                        'approved' => 'success',
                        'rejected' => 'danger',
                        default => 'info',
                    }),

                TextColumn::make('admin_comment')
                    ->label('Комментарий')
                    ->placeholder('—')
                    ->wrap(),
            ])
            ->headerActions([])
            ->recordActions([
                Action::make('approve')
                    ->label('Одобрить')
                    ->icon('heroicon-o-check')
                    ->color('success')
                    ->requiresConfirmation()
                    ->action(function (DayOffRequestDay $record): void {
                        $request = $record->request;

                        $changed = $record->status !== 'approved';

                        if ($changed) {
                            $request->resetNotification();
                        }

                        $record->update([
                            'status' => 'approved',
                            'reviewed_at' => now(),
                            'reviewed_by' => auth()->id(),
                        ]);

                        $request->syncStatusAndNotify();
                    }),

                Action::make('reject')
                    ->label('Отклонить')
                    ->icon('heroicon-o-x-mark')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->schema([
                        Textarea::make('admin_comment')
                            ->label('Причина отказа')
                            ->rows(3),
                    ])
                    ->action(function (DayOffRequestDay $record, array $data): void {
                        $request = $record->request;

                        $changed =
                            $record->status !== 'rejected'
                            || $record->admin_comment !== ($data['admin_comment'] ?? null);

                        if ($changed) {
                            $request->resetNotification();
                        }

                        $record->update([
                            'status' => 'rejected',
                            'admin_comment' => $data['admin_comment'] ?? null,
                            'reviewed_at' => now(),
                            'reviewed_by' => auth()->id(),
                        ]);

                        $request->syncStatusAndNotify();
                    }),

                Action::make('reset')
                    ->label('Сбросить')
                    ->icon('heroicon-o-arrow-path')
                    ->color('gray')
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
}