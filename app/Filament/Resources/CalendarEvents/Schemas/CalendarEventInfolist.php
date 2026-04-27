<?php

namespace App\Filament\Resources\CalendarEvents\Schemas;

use App\Models\CalendarEvent;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

class CalendarEventInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Grid::make(12)
                    ->schema([
                        Section::make('Событие')
                            ->schema([
                                TextEntry::make('title')
                                    ->label('Название')
                                    ->weight('bold')
                                    ->size('lg')
                                    ->columnSpanFull(),

                                TextEntry::make('type')
                                    ->label('Тип')
                                    ->html()
                                    ->formatStateUsing(fn (?string $state): HtmlString => self::typeBadge($state)),

                                IconEntry::make('is_active')
                                    ->label('Активно')
                                    ->boolean()
                                    ->trueIcon('heroicon-m-check-circle')
                                    ->falseIcon('heroicon-m-x-circle')
                                    ->trueColor('success')
                                    ->falseColor('gray'),

                                TextEntry::make('description')
                                    ->label('Описание')
                                    ->placeholder('—')
                                    ->columnSpanFull(),
                            ])
                            ->columns(2)
                            ->columnSpan(12),

                        Section::make('Даты')
                            ->schema([
                                TextEntry::make('start_date')
                                    ->label('Дата начала')
                                    ->date('d.m.Y'),

                                TextEntry::make('end_date')
                                    ->label('Дата окончания')
                                    ->date('d.m.Y')
                                    ->placeholder('—'),

                                TextEntry::make('repeat_type')
                                    ->label('Повторение')
                                    ->badge()
                                    ->color('gray')
                                    ->formatStateUsing(fn (?string $state): string => CalendarEvent::repeatOptions()[$state] ?? '—'),

                                TextEntry::make('repeat_until')
                                    ->label('Повторять до')
                                    ->date('d.m.Y')
                                    ->placeholder('—'),
                            ])
                            ->columns(2)
                            ->columnSpan(12),

                        Section::make('Системная информация')
                            ->schema([
                                TextEntry::make('priority')
                                    ->label('Приоритет')
                                    ->badge()
                                    ->color(fn ($state): string => (int) $state > 0 ? 'warning' : 'gray'),

                                TextEntry::make('created_at')
                                    ->label('Создано')
                                    ->dateTime('d.m.Y H:i')
                                    ->placeholder('—'),

                                TextEntry::make('updated_at')
                                    ->label('Обновлено')
                                    ->dateTime('d.m.Y H:i')
                                    ->placeholder('—'),
                            ])
                            ->columns(3)
                            ->columnSpan(12),
                    ]),
            ]);
    }

    protected static function typeBadge(?string $state): HtmlString
    {
        $label = CalendarEvent::typeOptions()[$state] ?? (string) $state;

        $style = match ($state) {
            CalendarEvent::TYPE_WORKFLOW => 'background:#CFE8FF;color:#111111;',
            CalendarEvent::TYPE_FINANCE => 'background:#CBEED9;color:#111111;',
            CalendarEvent::TYPE_HOLIDAY => 'background:#F3B8B8;color:#111111;',
            CalendarEvent::TYPE_PEAK => 'background:#F3E69C;color:#111111;',
            CalendarEvent::TYPE_VACATION => 'background:#CDBEFF;color:#111111;',
            CalendarEvent::TYPE_STRIKE => 'background:#F4C9A8;color:#111111;',
            default => 'background:#E9E9E9;color:#111111;',
        };

        return new HtmlString(
            '<span style="' . $style . 'display:inline-flex;align-items:center;border-radius:9999px;padding:6px 12px;font-size:12px;font-weight:600;line-height:1.2;">'
            . e($label)
            . '</span>'
        );
    }
}