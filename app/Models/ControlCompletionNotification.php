<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ControlCompletionNotification extends Model
{
    protected $fillable = [
        'control_response_id',
        'recipient_user_id',
        'status',
        'attempts',
        'telegram_message_id',
        'last_error',
        'last_attempt_at',
        'next_attempt_at',
        'last_dispatched_at',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'telegram_message_id' => 'integer',
            'last_attempt_at' => 'datetime',
            'next_attempt_at' => 'datetime',
            'last_dispatched_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }

    public function controlResponse(): BelongsTo
    {
        return $this->belongsTo(ControlResponse::class);
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }
}
