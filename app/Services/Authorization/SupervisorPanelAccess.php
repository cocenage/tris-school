<?php

namespace App\Services\Authorization;

use App\Models\CalendarEvent;
use App\Models\User;
use Filament\Facades\Filament;

class SupervisorPanelAccess
{
    public function canUseSupervisorPanel(?User $user): bool
    {
        return (bool) $user && $user->isApproved() && ($user->isAdmin()
            || ($user->role === 'supervisor' && $user->hasPanelAccess('education')));
    }

    public function canAccessCurrentRequestSurface(?User $user): bool
    {
        if (! $user || ! $user->isApproved()) {
            return false;
        }

        if (Filament::getCurrentPanel()?->getId() === 'education') {
            return $this->canUseSupervisorPanel($user);
        }

        $panel = Filament::getCurrentPanel();

        return $panel !== null
            ? $user->canAccessPanel($panel)
            : $user->canViewAllStaffActivity();
    }

    public function canManageCurrentRequestSurface(?User $user): bool
    {
        if (! $user || ! $user->isApproved()) {
            return false;
        }

        if (Filament::getCurrentPanel()?->getId() === 'education') {
            return $user->isAdmin();
        }

        $panel = Filament::getCurrentPanel();

        return $panel !== null
            ? $user->canAccessPanel($panel)
            : $user->canViewAllStaffActivity();
    }

    public function canUseCurrentQualityControlSurface(?User $user): bool
    {
        if (! $user || ! $user->isApproved()) {
            return false;
        }

        $panelId = Filament::getCurrentPanel()?->getId();

        if ($panelId === 'education') {
            return $this->canUseSupervisorPanel($user);
        }

        if ($panelId === 'admin') {
            $adminPanel = Filament::getPanel('admin');

            return $adminPanel !== null && $user->canAccessPanel($adminPanel);
        }

        return $panelId === null && $user->canViewAllStaffActivity();
    }

    public function canManageCurrentQualityControlSurface(?User $user): bool
    {
        if (! $user || ! $user->isApproved()) {
            return false;
        }

        $panelId = Filament::getCurrentPanel()?->getId();

        if ($panelId === 'education') {
            return $user->isAdmin();
        }

        if ($panelId === 'admin') {
            $adminPanel = Filament::getPanel('admin');

            return $adminPanel !== null && $user->canAccessPanel($adminPanel);
        }

        return $panelId === null && $user->canViewAllStaffActivity();
    }

    /** @return list<string> */
    public function allowedCalendarTypes(?User $user): array
    {
        if (! $user || ! $user->isApproved()) {
            return [];
        }

        if ($user->role === 'supervisor') {
            return array_keys(CalendarEvent::typeOptions());
        }

        return array_values(array_unique($user->allowedCalendarTypes()));
    }

    public function canViewCalendarType(?User $user, string $type): bool
    {
        return (bool) $user && $user->isApproved()
            && ($user->isAdmin() || in_array($type, $this->allowedCalendarTypes($user), true));
    }

    public function hasGeneralCalendarAccess(?User $user): bool
    {
        return (bool) $user && $user->isApproved() && ($user->isAdmin() || $this->allowedCalendarTypes($user) !== []);
    }
}
