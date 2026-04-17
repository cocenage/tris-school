<?php

namespace App\Filament\Resources\InventoryItems\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class InventoryItemInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Товар')
                    ->schema([
                        TextEntry::make('name')
                            ->label('Название'),

                        TextEntry::make('quantity')
                            ->label('Общее количество'),

                        TextEntry::make('active')
                            ->label('Активен')
                            ->badge()
                            ->formatStateUsing(fn (bool $state): string => $state ? 'Да' : 'Нет')
                            ->color(fn (bool $state): string => $state ? 'success' : 'gray'),

                        TextEntry::make('variants_list')
                            ->label('Варианты')
                            ->state(function ($record): string {
                                $variants = $record->variants ?? [];

                                if (empty($variants)) {
                                    return '—';
                                }

                                return collect($variants)
                                    ->map(function ($variant) {
                                        $type = trim((string) ($variant['type'] ?? ''));
                                        $size = trim((string) ($variant['size'] ?? ''));
                                        $quantity = (int) ($variant['quantity'] ?? 0);

                                        $parts = array_values(array_filter([$type, $size]));

                                        $label = ! empty($parts)
                                            ? implode(' • ', $parts)
                                            : 'Без варианта';

                                        return $label . ' — ' . $quantity;
                                    })
                                    ->implode(', ');
                            })
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
            ]);
    }
}