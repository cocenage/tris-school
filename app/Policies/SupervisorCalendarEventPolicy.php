<?php

namespace App\Policies;

use App\Models\CalendarEvent;
use App\Models\User;
use App\Services\Authorization\SupervisorPanelAccess;

class SupervisorCalendarEventPolicy
{
    public function viewAny(User $user): bool
    {
        $access = app(SupervisorPanelAccess::class);
        $eventTypes = array_keys(CalendarEvent::typeOptions());
        $allowedEventTypes = array_intersect($eventTypes, $access->allowedCalendarTypes($user));

        return $user->isApproved()
            && ($user->isAdmin() || $allowedEventTypes !== []);
    }

    public function view(User $user, CalendarEvent $event): bool
    {
        return $user->isApproved()
            && app(SupervisorPanelAccess::class)->canViewCalendarType($user, (string) $event->type);
    }

    public function create(User $user): bool
    {
        $access = app(SupervisorPanelAccess::class);
        $eventTypes = array_keys(CalendarEvent::typeOptions());
        $allowedEventTypes = array_intersect($eventTypes, $access->allowedCalendarTypes($user));

        return $user->isApproved()
            && ($user->isAdmin() || $allowedEventTypes !== []);
    }

    public function update(User $user, CalendarEvent $event): bool
    {
        return $this->view($user, $event);
    }

    public function delete(User $user, CalendarEvent $event): bool
    {
        return $this->view($user, $event);
    }
}
