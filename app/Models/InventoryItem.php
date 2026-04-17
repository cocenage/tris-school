<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InventoryItem extends Model
{
    protected $fillable = [
        'user_id',
        'main',
        'active',
    ];

    protected $casts = [
        'main' => 'array',
        'active' => 'boolean',
    ];

    public function requestLines(): HasMany
    {
        return $this->hasMany(InventoryRequestLine::class, 'inventory_item_id');
    }

    public function getNameAttribute(): string
    {
        $main = $this->main;

        if (! is_array($main)) {
            return 'Без названия';
        }

        return (string) ($main['name'] ?? $main['title'] ?? $main['product_name'] ?? 'Без названия');
    }

    public function getQuantityAttribute(): int
    {
        $main = $this->main;

        if (! is_array($main)) {
            return 0;
        }

        return (int) ($main['quantity'] ?? $main['qty'] ?? 0);
    }

    public function getVariantsAttribute(): array
    {
        $main = $this->main;

        if (! is_array($main)) {
            return [];
        }

        $variants = $main['variants'] ?? [];

        return is_array($variants) ? $variants : [];
    }
}