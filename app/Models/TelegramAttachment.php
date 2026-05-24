<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TelegramAttachment extends Model
{
    protected $connection = 'analytics';
    
    protected $fillable = [
        'telegram_message_id',
        'type',
        'file_id',
        'file_unique_id',
        'mime_type',
        'file_name',
        'file_size',
    ];

    public function message(): BelongsTo
    {
        return $this->belongsTo(TelegramMessage::class, 'telegram_message_id');
    }
}