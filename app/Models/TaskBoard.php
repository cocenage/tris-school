<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TaskBoard extends Model
{
    protected $fillable = [
        'task_room_id',
        'created_by',
        'title',
        'description',
        'status',
        'sort_order',
    ];

    public function room(): BelongsTo
    {
        return $this->belongsTo(TaskRoom::class, 'task_room_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function columns(): HasMany
    {
        return $this->hasMany(TaskBoardColumn::class)
            ->orderBy('sort_order');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class, 'task_board_id');
    }

    public function createDefaultColumns(): void
    {
        $columns = [
            [
                'title' => 'Новые',
                'slug' => 'new',
                'color' => '#F4F4F4',
                'sort_order' => 10,
                'is_done_column' => false,
            ],
            [
                'title' => 'В работе',
                'slug' => 'in_progress',
                'color' => '#E4F0FF',
                'sort_order' => 20,
                'is_done_column' => false,
            ],
            [
                'title' => 'Проверка',
                'slug' => 'review',
                'color' => '#FFEBC2',
                'sort_order' => 30,
                'is_done_column' => false,
            ],
            [
                'title' => 'Готово',
                'slug' => 'done',
                'color' => '#E5F7EB',
                'sort_order' => 40,
                'is_done_column' => true,
            ],
        ];

        foreach ($columns as $column) {
            $this->columns()->create($column);
        }
    }

    public function userCanView(User $user): bool
    {
        if (method_exists($user, 'canManageTasks') && $user->canManageTasks()) {
            return true;
        }

        if (isset($user->role) && in_array($user->role, ['admin', 'supervisor'], true)) {
            return true;
        }

        return $this->room?->hasUser($user) ?? false;
    }

    public function userCanManage(User $user): bool
    {
        return $this->room?->userCanManage($user) ?? false;
    }
}