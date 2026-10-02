<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TelegramScheduledMessageDelivery extends Model
{
    protected $fillable = [
        'scheduled_message_id',
        'control_type',
        'chat_id',
        'message_thread_id',
        'scheduled_for',
        'sent_at',
        'telegram_message_id',
        'status',
        'failure_reason',
    ];

    protected $casts = [
        'scheduled_for' => 'datetime',
        'sent_at' => 'datetime',
        'telegram_message_id' => 'integer',
    ];

    public function responses(): HasMany
    {
        return $this->hasMany(TelegramScheduledMessageResponse::class, 'delivery_id');
    }

    public function scheduledMessage(): BelongsTo
    {
        return $this->belongsTo(TelegramScheduledMessage::class, 'scheduled_message_id');
    }
}
