<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VacationRequest extends Model
{
    protected $fillable = [
        'user_id',
        'start_date',
        'end_date',
        'days_count',
        'reason',
        'status',
        'admin_comment',
        'notified_at',
        'reviewed_by',
        'reviewed_at',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'notified_at' => 'datetime',
        'reviewed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function days(): HasMany
    {
        return $this->hasMany(VacationRequestDay::class)->orderBy('date');
    }

    public function resetNotification(): void
    {
        $this->forceFill([
            'notified_at' => null,
        ])->saveQuietly();
    }
}