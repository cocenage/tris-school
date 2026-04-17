<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryRequestLine extends Model
{
    protected $fillable = [
        'inventory_request_id',
        'inventory_item_id',
        'user_id',
        'item_name',
        'type_name',
        'size_name',
        'variant_label',
        'requested_qty',
        'issued_qty',
        'status',
        'admin_comment',
        'processed_at',
        'processed_by',
    ];

    protected $casts = [
        'processed_at' => 'datetime',
    ];

    public function request(): BelongsTo
    {
        return $this->belongsTo(InventoryRequest::class, 'inventory_request_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'inventory_item_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function processor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by');
    }
}