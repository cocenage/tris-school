<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TaskRoom extends Model
{
    protected $fillable = [
        'created_by',
        'title',
        'description',
        'status',
        'color',
        'icon',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'task_room_user')
            ->withPivot('role')
            ->withTimestamps();
    }

    public function boards(): HasMany
    {
        return $this->hasMany(TaskBoard::class)
            ->orderBy('sort_order');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function userRole(User $user): ?string
    {
        $member = $this->users()
            ->where('users.id', $user->id)
            ->first();

        return $member?->pivot?->role;
    }

    public function hasUser(User $user): bool
    {
        return $this->users()
            ->where('users.id', $user->id)
            ->exists();
    }

    public function userCanManage(User $user): bool
    {
        if (method_exists($user, 'canManageTasks') && $user->canManageTasks()) {
            return true;
        }

        if (isset($user->role) && in_array($user->role, ['admin', 'supervisor'], true)) {
            return true;
        }

        return $this->users()
            ->where('users.id', $user->id)
            ->wherePivotIn('role', ['owner', 'manager'])
            ->exists();
    }
}