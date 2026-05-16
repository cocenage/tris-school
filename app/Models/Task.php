<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Task extends Model
{
    protected $fillable = [
        'task_room_id',
        'task_board_id',
        'task_board_column_id',
        'created_by',
        'assigned_to',
        'title',
        'description',
        'status',
        'priority',
        'sort_order',
        'starts_at',
        'deadline_at',
        'completed_at',
        'cancelled_at',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'deadline_at' => 'datetime',
        'completed_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function room(): BelongsTo
    {
        return $this->belongsTo(TaskRoom::class, 'task_room_id');
    }

    public function board(): BelongsTo
    {
        return $this->belongsTo(TaskBoard::class, 'task_board_id');
    }

    public function column(): BelongsTo
    {
        return $this->belongsTo(TaskBoardColumn::class, 'task_board_column_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(TaskComment::class)->latest();
    }

    public function checklistItems(): HasMany
    {
        return $this->hasMany(TaskChecklistItem::class)->orderBy('sort_order');
    }

public function scopeVisibleFor(Builder $query, User $user): Builder
{
    if (method_exists($user, 'canManageTasks') && $user->canManageTasks()) {
        return $query;
    }

    if (isset($user->role) && in_array($user->role, ['admin', 'supervisor'], true)) {
        return $query;
    }

    return $query->where(function (Builder $query) use ($user) {
        $query
            ->where('assigned_to', $user->id)
            ->orWhereHas('assignees', function (Builder $query) use ($user) {
                $query->where('users.id', $user->id);
            })
            ->orWhereHas('room.users', function (Builder $query) use ($user) {
                $query->where('users.id', $user->id);
            });
    });
}

    public function scopeNotClosed(Builder $query): Builder
    {
        return $query->whereNotIn('status', ['done', 'cancelled']);
    }

    public function isDone(): bool
    {
        return $this->status === 'done';
    }

    public function isCancelled(): bool
    {
        return $this->status === 'cancelled';
    }

    public function isOverdue(): bool
    {
        return $this->deadline_at
            && ! $this->isDone()
            && ! $this->isCancelled()
            && $this->deadline_at->isPast();
    }

    public function displayStatus(): string
    {
        return match ($this->status) {
            'new' => 'Новая',
            'in_progress' => 'В работе',
            'review' => 'Проверка',
            'done' => 'Готово',
            'overdue' => 'Просрочена',
            'cancelled' => 'Отменена',
            default => 'Задача',
        };
    }

    public function displayPriority(): string
    {
        return match ($this->priority) {
            'low' => 'Низкий',
            'normal' => 'Обычный',
            'high' => 'Высокий',
            'urgent' => 'Срочный',
            default => 'Обычный',
        };
    }

    public function checklistProgress(): string
    {
        $total = $this->checklistItems->count();

        if ($total === 0) {
            return '0/0';
        }

        $done = $this->checklistItems->where('is_done', true)->count();

        return "{$done}/{$total}";
    }

    public function markAsDone(): void
    {
        $doneColumn = $this->board?->columns()
            ->where('is_done_column', true)
            ->first();

        $this->update([
            'status' => 'done',
            'task_board_column_id' => $doneColumn?->id ?? $this->task_board_column_id,
            'completed_at' => now(),
        ]);
    }

    public function moveToColumn(TaskBoardColumn $column): void
    {
        $status = match ($column->slug) {
            'new' => 'new',
            'in_progress' => 'in_progress',
            'review' => 'review',
            'done' => 'done',
            default => $this->status,
        };

        $this->update([
            'task_board_column_id' => $column->id,
            'status' => $status,
            'completed_at' => $column->is_done_column ? now() : null,
        ]);
    }

    public function assignees(): BelongsToMany
{
    return $this->belongsToMany(User::class, 'task_assignees')
        ->withTimestamps();
}
}