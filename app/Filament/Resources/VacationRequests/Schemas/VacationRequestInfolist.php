<?php

namespace App\Filament\Resources\VacationRequests\Schemas;

use Carbon\Carbon;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class VacationRequestInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Заявка')
                    ->schema([
                        TextEntry::make('user.name')
                            ->label('Сотрудник'),

                        TextEntry::make('status')
                            ->label('Статус')
                            ->badge()
                            ->formatStateUsing(fn (string $state): string => match ($state) {
                                'approved' => 'Одобрено',
                                'rejected' => 'Отклонено',
                                'partially_approved' => 'Частично одобрено',
                                default => 'На рассмотрении',
                            })
                            ->color(fn (string $state): string => match ($state) {
                                'approved' => 'success',
                                'rejected' => 'danger',
                                'partially_approved' => 'warning',
                                default => 'info',
                            }),

                        TextEntry::make('days')
                            ->label('Даты')
                            ->state(function ($record): string {
                                return $record->days
                                    ->sortBy('date')
                                    ->map(fn ($day) => Carbon::parse($day->date)->format('d.m.Y'))
                                    ->implode(', ');
                            })
                            ->columnSpanFull(),

                        TextEntry::make('reason')
                            ->label('Причина')
                            ->columnSpanFull(),

                        TextEntry::make('admin_comment')
                            ->label('Комментарий администратора')
                            ->placeholder('—')
                            ->columnSpanFull(),

                        TextEntry::make('created_at')
                            ->label('Создано')
                            ->dateTime('d.m.Y H:i'),
                    ])
                    ->columns(2),
            ]);
    }
}