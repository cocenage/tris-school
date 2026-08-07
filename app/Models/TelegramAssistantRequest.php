<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TelegramAssistantRequest extends Model
{
    protected $connection = 'analytics';

    protected $fillable = [
        'telegram_chat_id',
        'telegram_topic_id',
        'telegram_user_id',
        'linked_user_id',
        'root_message_id',
        'last_bot_message_id',
        'category',
        'status',
        'original_text',
        'is_sensitive',
        'metadata',
    ];

    protected $casts = [
        'is_sensitive' => 'boolean',
        'metadata' => 'array',
    ];

    public function messages(): HasMany
    {
        return $this->hasMany(TelegramAssistantRequestMessage::class, 'request_id');
    }
}
