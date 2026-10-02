<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TelegramScheduledMessageResponse extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['responded_at' => 'datetime', 'response_latency_seconds' => 'integer'];

    public function delivery(): BelongsTo
    {
        return $this->belongsTo(TelegramScheduledMessageDelivery::class, 'delivery_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
