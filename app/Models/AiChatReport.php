<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiChatReport extends Model
{
    protected $fillable = [
        'report_date',
        'chat_id',
        'messages_count',
        'prompt',
        'result',
        'meta',
    ];

    protected $casts = [
        'report_date' => 'date',
        'meta' => 'array',
    ];
}