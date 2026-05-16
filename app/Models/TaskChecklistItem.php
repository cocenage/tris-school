<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaskChecklistItem extends Model
{
    protected $fillable = [
        'task_id',
        'title',
        'is_done',
        'done_by',
        'done_at',
        'sort_order',
    ];

    protected $casts = [
        'is_done' => 'boolean',
        'done_at' => 'datetime',
    ];

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function doneBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'done_by');
    }

    public function toggleDone(int $userId): void
    {
        $this->update([
            'is_done' => ! $this->is_done,
            'done_by' => ! $this->is_done ? $userId : null,
            'done_at' => ! $this->is_done ? now() : null,
        ]);
    }
}