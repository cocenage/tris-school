<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TrisMareSnapshot extends Model
{
    protected $fillable = [
        'user_id',
        'employee_external_id',
        'employee_name',
        'daily_points',
        'weekly_points',
        'total_points',
        'left_to_230',
        'status',
        'progress_percent',
        'comment',
        'working_days',
        'rating',
        'daily_history',
        'raw_data',
        'synced_at',
    ];

    protected $casts = [
        'daily_points' => 'integer',
        'weekly_points' => 'integer',
        'total_points' => 'integer',
        'left_to_230' => 'integer',
        'progress_percent' => 'integer',
        'working_days' => 'integer',
        'rating' => 'integer',
        'daily_history' => 'array',
        'raw_data' => 'array',
        'synced_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function getLeftTo320Attribute(): int
    {
        return max(0, 320 - $this->total_points);
    }

    public function getLeftTo400Attribute(): int
    {
        return max(0, 400 - $this->total_points);
    }
}