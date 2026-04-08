<?php

namespace App\Filament\Resources\CalendarEvents\Schemas;

use App\Models\CalendarEvent;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

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
                            ->html()
                            ->formatStateUsing(function (?string $state): HtmlString {
                                $label = CalendarEvent::typeOptions()[$state] ?? (string) $state;

                                $style = match ($state) {
                                    'workflow' => 'background:#CFE8FF;color:#111111;',
                                    'finance' => 'background:#CBEED9;color:#111111;',
                                    'holiday' => 'background:#F3B8B8;color:#111111;',
                                    'peak' => 'background:#F3E69C;color:#111111;',
                                    'vacation' => 'background:#CDBEFF;color:#111111;',
                                    'strike' => 'background:#F4C9A8;color:#111111;',
                                    default => 'background:#E9E9E9;color:#111111;',
                                };

                                return new HtmlString(
                                    '<span style="'
                                    . $style
                                    . 'display:inline-flex;align-items:center;border-radius:9999px;padding:6px 12px;font-size:12px;font-weight:600;line-height:1.2;">'
                                    . e($label)
                                    . '</span>'
                                );
                            }),

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
                              ->default(true)
                            ->boolean(),
                    ])
                    ->columns(2),
            ]);
    }
}