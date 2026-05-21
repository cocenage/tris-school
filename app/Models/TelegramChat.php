<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TelegramChat extends Model
{
    protected $fillable = [
        'telegram_chat_id',
        'title',
        'type',
        'is_enabled',
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
    ];

    public function topics(): HasMany
    {
        return $this->hasMany(TelegramTopic::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(TelegramMessage::class);
    }
}