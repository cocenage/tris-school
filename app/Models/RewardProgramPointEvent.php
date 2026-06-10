<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class RewardProgramPointEvent extends Model
{
    protected $fillable = [
        'reward_program_id',
        'user_id',
        'created_by',
        'points',
        'reason',
        'event_date',
        'source_type',
        'source_id',
    ];

    protected $casts = [
        'points' => 'integer',
        'event_date' => 'date',
    ];

    public function program(): BelongsTo
    {
        return $this->belongsTo(RewardProgram::class, 'reward_program_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }
}