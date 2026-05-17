<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TelegramWorkMessage extends Model
{
    protected $fillable = [
        'chat_id',
        'chat_title',
        'thread_id',
        'message_id',
        'telegram_user_id',
        'username',
        'first_name',
        'last_name',
        'reply_to_message_id',
        'text',
        'raw',
        'sent_at',
    ];

    protected $casts = [
        'raw' => 'array',
        'sent_at' => 'datetime',
    ];
}