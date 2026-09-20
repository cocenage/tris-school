<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TelegramTopic extends Model
{
    protected $connection = 'analytics';

    protected static function booted(): void
    {
        static::updated(function (self $topic): void {
            if ($topic->wasChanged('apartment_id')) {
                TelegramOperationalEvent::query()
                    ->where('telegram_topic_id', $topic->id)
                    ->update(['apartment_id' => $topic->apartment_id]);
            }
        });
    }

    protected $fillable = [
        'telegram_chat_id',
        'telegram_thread_id',
        'apartment_id',
        'title',
        'purpose',
        'is_enabled',
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
    ];

    public function chat(): BelongsTo
    {
        return $this->belongsTo(TelegramChat::class, 'telegram_chat_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(TelegramMessage::class);
    }

    public function apartment(): BelongsTo
    {
        return $this->belongsTo(Apartment::class);
    }
}
