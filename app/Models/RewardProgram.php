<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RewardProgram extends Model
{
    protected $fillable = [
        'name',
        'description',
        'starts_at',
        'ends_at',
        'targets',
        'is_active',
    ];

    protected $casts = [
        'starts_at' => 'date',
        'ends_at' => 'date',
        'targets' => 'array',
        'is_active' => 'boolean',
    ];

    public function pointEvents(): HasMany
    {
        return $this->hasMany(RewardProgramPointEvent::class);
    }

    public function pointsForUser(int $userId): int
    {
        return (int) $this->pointEvents()
            ->where('user_id', $userId)
            ->sum('points');
    }

    
}