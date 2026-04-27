<?php

namespace App\Filament\Resources\InventoryRequests\Schemas;

use App\Models\InventoryRequest;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class InventoryRequestInfolist
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

                                TextEntry::make('processed_at')
                                    ->label('Обработано')
                                    ->dateTime('d.m.Y H:i')
                                    ->placeholder('—'),
                            ])
                            ->columns(2)
                            ->columnSpan([
                                'default' => 12,
                                'xl' => 5,
                            ]),

                        Section::make('Позиции')
                            ->schema([
                                TextEntry::make('items')
                                    ->label('Что запросили')
                                    ->state(function (InventoryRequest $record): string {
                                        if ($record->lines->isEmpty()) {
                                            return '—';
                                        }

                                        return $record->lines
                                            ->map(function ($line) {
                                                $name = $line->item_name;

                                                if ($line->variant_label) {
                                                    $name .= ' (' . $line->variant_label . ')';
                                                }

                                                $issued = (int) $line->issued_qty;
                                                $requested = (int) $line->requested_qty;

                                                return "{$name}: запрошено {$requested}, выдано {$issued}";
                                            })
                                            ->implode("\n");
                                    })
                                    ->listWithLineBreaks()
                                    ->columnSpanFull(),
                            ])
                            ->columnSpan([
                                'default' => 12,
                                'xl' => 7,
                            ]),

                        Section::make('Комментарии')
                            ->schema([
                                TextEntry::make('comment')
                                    ->label('Комментарий пользователя')
                                    ->placeholder('—')
                                    ->columnSpanFull(),

                                TextEntry::make('admin_comment')
                                    ->label('Комментарий администратора')
                                    ->placeholder('—')
                                    ->columnSpanFull(),
                            ])
                            ->columnSpan(12),

                        Section::make('Системная информация')
                            ->schema([
                                TextEntry::make('id')
                                    ->label('ID')
                                    ->copyable(),

                                TextEntry::make('updated_at')
                                    ->label('Обновлено')
                                    ->dateTime('d.m.Y H:i')
                                    ->placeholder('—'),
                            ])
                            ->columns(2)
                            ->columnSpan(12),
                    ]),
            ]);
    }

    protected static function statusLabel(?string $status): string
    {
        return match ($status) {
            'issued' => 'Выдано',
            'partially_issued' => 'Выдано частично',
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