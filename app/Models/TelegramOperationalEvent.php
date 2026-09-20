<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TelegramOperationalEvent extends Model
{
    protected $connection = 'analytics';

    protected $fillable = [
        'event_key',
        'root_message_id',
        'telegram_chat_id',
        'telegram_topic_id',
        'apartment_id',
        'primary_type',
        'types',
        'summary',
        'status',
        'confidence',
        'uncertainty',
        'subject_key',
        'first_observed_at',
        'last_observed_at',
        'resolved_at',
    ];

    protected $casts = [
        'types' => 'array',
        'first_observed_at' => 'datetime',
        'last_observed_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    public function rootMessage(): BelongsTo
    {
        return $this->belongsTo(TelegramMessage::class, 'root_message_id');
    }

    public function chat(): BelongsTo
    {
        return $this->belongsTo(TelegramChat::class, 'telegram_chat_id');
    }

    public function topic(): BelongsTo
    {
        return $this->belongsTo(TelegramTopic::class, 'telegram_topic_id');
    }

    public function apartment(): BelongsTo
    {
        return $this->belongsTo(Apartment::class);
    }

    public function evidence(): HasMany
    {
        return $this->hasMany(TelegramOperationalEventEvidence::class, 'operational_event_id');
    }
}
