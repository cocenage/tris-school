<?php

namespace App\Policies;

use App\Models\User;
use App\Services\Authorization\SupervisorPanelAccess;
use Illuminate\Database\Eloquent\Model;

class SupervisorRequestPolicy
{
    public function viewAny(User $user): bool
    {
        return app(SupervisorPanelAccess::class)->canAccessCurrentRequestSurface($user);
    }

    public function view(User $user, Model $request): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return app(SupervisorPanelAccess::class)->canManageCurrentRequestSurface($user);
    }

    public function update(User $user, Model $request): bool
    {
        return app(SupervisorPanelAccess::class)->canManageCurrentRequestSurface($user);
    }

    public function delete(User $user, Model $request): bool
    {
        return app(SupervisorPanelAccess::class)->canManageCurrentRequestSurface($user);
    }

    public function review(User $user, Model $request): bool
    {
        return app(SupervisorPanelAccess::class)->canAccessCurrentRequestSurface($user);
    }
}
