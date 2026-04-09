<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DayOffRequest extends Model
{
    protected $fillable = [
        'user_id',
        'reason',
        'status',
        'admin_comment',
        'submitted_at',
        'reviewed_at',
        'reviewed_by',
    ];

    protected $casts = [
        'submitted_at' => 'datetime',
        'reviewed_at' => 'datetime',
    ];
    protected $appends = ['user_name'];

    public function getUserNameAttribute(): string
    {
        return $this->user?->name ?? '—';
    }

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
        return $this->hasMany(DayOffRequestDay::class);
    }

    public function recalculateStatus(): void
    {
        $statuses = $this->days()->pluck('status');

        if ($statuses->isEmpty()) {
            $this->update(['status' => 'pending']);
            return;
        }

        $unique = $statuses->unique()->values();

        if ($unique->count() === 1) {
            $this->update(['status' => $unique->first()]);
            return;
        }

        if ($unique->contains('approved') && $unique->contains('rejected')) {
            $this->update(['status' => 'partially_approved']);
            return;
        }

        if ($unique->contains('pending') && ($unique->contains('approved') || $unique->contains('rejected'))) {
            $this->update(['status' => 'pending']);
            return;
        }

        $this->update(['status' => 'pending']);
    }
}