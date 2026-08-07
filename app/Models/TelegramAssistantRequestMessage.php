<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TelegramAssistantRequestMessage extends Model
{
    protected $connection = 'analytics';

    protected $fillable = [
        'request_id',
        'telegram_message_id',
        'direction',
    ];

    public function request(): BelongsTo
    {
        return $this->belongsTo(TelegramAssistantRequest::class, 'request_id');
    }
}
