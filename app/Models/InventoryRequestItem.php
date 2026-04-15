<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryRequestItem extends Model
{
    protected $fillable = [
        'inventory_request_id',
        'inventory_item_id',
        'item_name',
        'requested_qty',
        'approved_qty',
        'status',
        'admin_comment',
    ];

    public function request(): BelongsTo
    {
        return $this->belongsTo(InventoryRequest::class, 'inventory_request_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'inventory_item_id');
    }
}