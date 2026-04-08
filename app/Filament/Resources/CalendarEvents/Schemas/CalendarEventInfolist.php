<?php

namespace App\Filament\Resources\CalendarEvents\Schemas;

use App\Models\CalendarEvent;
use Filament\Infolists\Components\IconEntry;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class CalendarEventInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Событие')
                    ->schema([
                        TextEntry::make('title')
                            ->label('Название'),

                        TextEntry::make('type')
                            ->label('Тип')
                            ->formatStateUsing(fn (?string $state): string => CalendarEvent::typeOptions()[$state] ?? (string) $state),

                        TextEntry::make('description')
                            ->label('Описание')
                            ->placeholder('—'),

                        TextEntry::make('start_date')
                            ->label('Дата начала')
                            ->date('d.m.Y'),

                        TextEntry::make('end_date')
                            ->label('Дата окончания')
                            ->date('d.m.Y')
                            ->placeholder('—'),

                        TextEntry::make('repeat_type')
                            ->label('Повторение')
                            ->formatStateUsing(fn (?string $state): string => CalendarEvent::repeatOptions()[$state] ?? (string) $state),

                        TextEntry::make('repeat_until')
                            ->label('Повторять до')
                            ->date('d.m.Y')
                            ->placeholder('—'),

                        TextEntry::make('priority')
                            ->label('Приоритет'),

                        IconEntry::make('is_active')
                            ->label('Активно')
                            ->boolean(),
                    ]),
            ]);
    }
}