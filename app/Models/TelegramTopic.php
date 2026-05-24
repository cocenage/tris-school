<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TelegramTopic extends Model
{
    protected $connection = 'analytics';
    
protected $fillable = [
    'telegram_chat_id',
    'telegram_thread_id',
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
}