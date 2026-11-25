<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StructuredRequestDecision extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'snapshot' => 'array',
        'version' => 'integer',
        'card_sync_attempts' => 'integer',
        'source_message_id' => 'integer',
        'telegram_message_id' => 'integer',
    ];
}
