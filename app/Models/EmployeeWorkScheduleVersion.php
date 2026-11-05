<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

class EmployeeWorkScheduleVersion extends Model
{
    protected $fillable = [
        'user_id',
        'effective_from',
        'version_type',
        'employment_start_date',
        'weekend_days',
        'previous_values',
        'new_values',
        'actor_id',
        'actor_name_snapshot',
        'reason',
    ];

    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            'employment_start_date' => 'date',
            'weekend_days' => 'array',
            'previous_values' => 'array',
            'new_values' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new RuntimeException('Schedule versions are immutable.'));
        static::deleting(fn () => throw new RuntimeException('Schedule history is append-only.'));
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
