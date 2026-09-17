<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class TelegramMessage extends Model
{
    protected $connection = 'analytics';
    
    protected $fillable = [
        'telegram_chat_id',
        'telegram_topic_id',
        'telegram_user_id',
        'message_id',
        'message_type',
        'text',
        'caption',
        'sent_at',
        'edited_at',
        'raw',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
        'edited_at' => 'datetime',
        'raw' => 'array',
    ];

    public function chat(): BelongsTo
    {
        return $this->belongsTo(TelegramChat::class, 'telegram_chat_id');
    }

    public function topic(): BelongsTo
    {
        return $this->belongsTo(TelegramTopic::class, 'telegram_topic_id');
    }

    public function telegramUser(): BelongsTo
    {
        return $this->belongsTo(TelegramUser::class, 'telegram_user_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(TelegramAttachment::class);
    }

    public function operationalObservations(): HasMany
    {
        return $this->hasMany(TelegramOperationalObservation::class);
    }

    public function operationalEvidence(): HasManyThrough
    {
        return $this->hasManyThrough(
            TelegramOperationalEventEvidence::class,
            TelegramOperationalObservation::class,
            'telegram_message_id',
            'observation_id',
        );
    }
}
