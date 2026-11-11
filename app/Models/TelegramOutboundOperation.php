<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

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

    public function events(): HasMany
    {
        return $this->hasMany(TelegramOutboundOperationEvent::class, 'operation_id')->orderBy('id');
    }
}
