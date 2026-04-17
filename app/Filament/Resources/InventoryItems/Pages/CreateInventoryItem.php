<?php

namespace App\Filament\Resources\InventoryItems\Pages;

use App\Filament\Resources\InventoryItems\InventoryItemResource;
use Filament\Resources\Pages\CreateRecord;

class CreateInventoryItem extends CreateRecord
{
    protected static string $resource = InventoryItemResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['user_id'] = auth()->id();

        $data['main'] = $this->normalizeMain($data['main'] ?? []);

        return $data;
    }

    protected function normalizeMain(array $main): array
    {
        $variants = collect($main['variants'] ?? [])
            ->filter(fn ($variant) => is_array($variant))
            ->map(function ($variant) {
                return [
                    'type' => filled($variant['type'] ?? null) ? trim((string) $variant['type']) : null,
                    'size' => filled($variant['size'] ?? null) ? trim((string) $variant['size']) : null,
                    'quantity' => max(0, (int) ($variant['quantity'] ?? 0)),
                ];
            })
            ->values()
            ->all();

        return [
            'name' => trim((string) ($main['name'] ?? '')),
            'quantity' => max(0, (int) ($main['quantity'] ?? 0)),
            'variants' => $variants,
        ];
    }
}