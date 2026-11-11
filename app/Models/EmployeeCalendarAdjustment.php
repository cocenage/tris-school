<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

class EmployeeCalendarAdjustment extends Model
{
    protected $fillable = [
        'user_id',
        'starts_on',
        'ends_on',
        'operation',
        'category',
        'previous_state',
        'new_state',
        'supersedes_request_type',
        'supersedes_request_day_id',
        'supersedes_request_date',
        'replacement_date',
        'actor_id',
        'actor_name_snapshot',
        'reverses_adjustment_id',
        'reason',
    ];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'supersedes_request_date' => 'date',
            'replacement_date' => 'date',
            'previous_state' => 'array',
            'new_state' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new RuntimeException('Calendar adjustments are immutable.'));
        static::deleting(fn () => throw new RuntimeException('Calendar adjustment history is append-only.'));
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function reversal(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_adjustment_id');
    }

    public function reversals(): HasMany
    {
        return $this->hasMany(self::class, 'reverses_adjustment_id');
    }
}
