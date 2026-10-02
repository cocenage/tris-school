<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TelegramScheduledControlSummaryDelivery extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['summary' => 'array', 'queued_at' => 'datetime', 'sent_at' => 'datetime'];
}
