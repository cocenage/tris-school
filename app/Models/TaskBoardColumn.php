<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TaskBoardColumn extends Model
{
    protected $fillable = [
        'task_board_id',
        'title',
        'slug',
        'color',
        'sort_order',
        'is_done_column',
    ];

    protected $casts = [
        'is_done_column' => 'boolean',
    ];

    public function board(): BelongsTo
    {
        return $this->belongsTo(TaskBoard::class, 'task_board_id');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class, 'task_board_column_id')
            ->orderBy('sort_order')
            ->orderBy('created_at');
    }
}