<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MobilityAlertMessage extends Model
{
    protected $fillable = [
        'mobility_alert_id',
        'message_type',
        'chat_id',
        'thread_id',
        'telegram_message_id',
        'text',
        'sent_at',
        'deleted_at',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public function alert()
    {
        return $this->belongsTo(MobilityAlert::class, 'mobility_alert_id');
    }
}