<?php

namespace App\Filament\Resources\DayOffRequests\Schemas;

use Carbon\Carbon;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class DayOffRequestInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Grid::make(12)
                    ->schema([
                        Section::make('Заявка')
                            ->schema([
                                TextEntry::make('user.name')
                                    ->label('Сотрудник')
                                    ->weight('bold'),

                                TextEntry::make('status')
                                    ->label('Статус')
                                    ->badge()
                                    ->formatStateUsing(fn (?string $state): string => self::statusLabel($state))
                                    ->color(fn (?string $state): string => self::statusColor($state)),

                                TextEntry::make('created_at')
                                    ->label('Создано')
                                    ->dateTime('d.m.Y H:i'),

                                TextEntry::make('updated_at')
                                    ->label('Обновлено')
                                    ->dateTime('d.m.Y H:i')
                                    ->placeholder('—'),
                            ])
                            ->columns(2)
                            ->columnSpan(12),

                        Section::make('Даты')
                            ->schema([
                                TextEntry::make('days')
                                    ->label('Запрошенные даты')
                                    ->state(function ($record): string {
                                        if ($record->days->isEmpty()) {
                                            return '—';
                                        }

                                        return $record->days
                                            ->sortBy('date')
                                            ->map(function ($day): string {
                                                $date = Carbon::parse($day->date)->format('d.m.Y');

                                                $status = match ($day->status) {
                                                    'approved' => 'одобрено',
                                                    'rejected' => 'отклонено',
                                                    default => 'на рассмотрении',
                                                };

                                                return "{$date} — {$status}";
                                            })
                                            ->implode("\n");
                                    })
                                    ->listWithLineBreaks()
                                    ->columnSpanFull(),
                            ])
                            ->columnSpan(12),

                        Section::make('Комментарии')
                            ->schema([
                                TextEntry::make('reason')
                                    ->label('Причина сотрудника')
                                    ->placeholder('—')
                                    ->columnSpanFull(),

                                TextEntry::make('admin_comment')
                                    ->label('Комментарий администратора')
                                    ->placeholder('—')
                                    ->columnSpanFull(),
                            ])
                            ->columnSpan(12),
                    ]),
            ]);
    }

    protected static function statusLabel(?string $status): string
    {
        return match ($status) {
            'approved' => 'Одобрено',
            'rejected' => 'Отклонено',
            'partially_approved' => 'Частично одобрено',
            default => 'На рассмотрении',
        };
    }

    protected static function statusColor(?string $status): string
    {
        return match ($status) {
            'approved' => 'success',
            'rejected' => 'danger',
            'partially_approved' => 'warning',
            default => 'info',
        };
    }
}