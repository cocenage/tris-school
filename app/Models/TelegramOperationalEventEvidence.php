<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TelegramOperationalEventEvidence extends Model
{
    protected $connection = 'analytics';

    protected $table = 'telegram_operational_event_evidence';

    protected $fillable = [
        'operational_event_id',
        'observation_id',
        'role',
        'transition',
        'status_before',
        'status_after',
        'confidence',
        'uncertainty',
        'occurred_at',
        'is_current_revision',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
        'is_current_revision' => 'boolean',
    ];

    public function event(): BelongsTo
    {
        return $this->belongsTo(TelegramOperationalEvent::class, 'operational_event_id');
    }

    public function observation(): BelongsTo
    {
        return $this->belongsTo(TelegramOperationalObservation::class, 'observation_id');
    }
}
