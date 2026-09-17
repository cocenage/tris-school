<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TelegramOperationalObservation extends Model
{
    protected $connection = 'analytics';

    protected $fillable = [
        'telegram_message_id',
        'source_revision_hash',
        'evaluation_kind',
        'state',
        'outcome',
        'reason_code',
        'confidence',
        'uncertainty',
        'unanswered_due_at',
        'is_current_revision',
        'processed_at',
        'error_code',
    ];

    protected $casts = [
        'unanswered_due_at' => 'datetime',
        'is_current_revision' => 'boolean',
        'processed_at' => 'datetime',
    ];

    public function message(): BelongsTo
    {
        return $this->belongsTo(TelegramMessage::class, 'telegram_message_id');
    }

    public function evidence(): HasMany
    {
        return $this->hasMany(TelegramOperationalEventEvidence::class, 'observation_id');
    }
}
