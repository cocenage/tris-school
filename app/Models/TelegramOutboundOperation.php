<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TelegramOutboundOperation extends Model
{
    protected $guarded = [];

    protected $casts = [
        'scheduled_for' => 'datetime',
        'attempted_at' => 'datetime',
        'finished_at' => 'datetime',
        'telegram_message_id' => 'integer',
        'error_code' => 'integer',
        'attempts' => 'integer',
    ];
}
