<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TelegramScheduledControlFollowup extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'due_at' => 'datetime',
        'sent_at' => 'datetime',
        'telegram_message_id' => 'integer',
    ];

    public function delivery(): BelongsTo
    {
        return $this->belongsTo(TelegramScheduledMessageDelivery::class, 'delivery_id');
    }
}
