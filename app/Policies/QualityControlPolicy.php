<?php

namespace App\Policies;

use App\Models\User;
use App\Services\Authorization\SupervisorPanelAccess;
use Illuminate\Database\Eloquent\Model;

class QualityControlPolicy
{
    public function viewAny(User $user): bool
    {
        return app(SupervisorPanelAccess::class)->canUseCurrentQualityControlSurface($user);
    }

    public function view(User $user, Model $record): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return app(SupervisorPanelAccess::class)->canManageCurrentQualityControlSurface($user);
    }

    public function update(User $user, Model $record): bool
    {
        return app(SupervisorPanelAccess::class)->canManageCurrentQualityControlSurface($user);
    }

    public function delete(User $user, Model $record): bool
    {
        return app(SupervisorPanelAccess::class)->canManageCurrentQualityControlSurface($user);
    }

    public function deleteAny(User $user): bool
    {
        return app(SupervisorPanelAccess::class)->canManageCurrentQualityControlSurface($user);
    }
}
