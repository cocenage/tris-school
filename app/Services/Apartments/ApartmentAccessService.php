<?php

namespace App\Services\Apartments;

use App\Models\Apartment;
use App\Models\ApartmentUserAccess;
use App\Models\User;
use Carbon\Carbon;
use DateTimeInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;

class ApartmentAccessService
{
    public function visibleQuery(?User $user): Builder
    {
        if (! $user) {
            return Apartment::query()->whereKey(0);
        }

        if ($user->isAdmin() || ($user->isApproved() && $user->hasPanelAccess('admin'))) {
            return Apartment::query();
        }

        return Apartment::query()
            ->where('is_active', true)
            ->where('information_status', 'published')
            ->whereHas('accessGrants', fn (Builder $grants): Builder => $grants
                ->where('user_id', $user->id)
                ->active());
    }

    public function canView(?User $user, Apartment $apartment): bool
    {
        return $this->visibleQuery($user)->whereKey($apartment->getKey())->exists();
    }

    public function canManage(?User $user): bool
    {
        return (bool) $user && $user->isApproved() && ($user->isAdmin() || $user->hasPanelAccess('admin'));
    }

    public function canViewDrafts(?User $user): bool
    {
        return $this->canManage($user);
    }

    public function grant(User $actor, Apartment $apartment, User|int $user, DateTimeInterface|string|null $expiresAt = null): ApartmentUserAccess
    {
        $this->authorizeManage($actor);

        $granteeId = $user instanceof User ? $user->getKey() : $user;
        $expires = $expiresAt instanceof DateTimeInterface
            ? Carbon::instance($expiresAt)
            : ($expiresAt ? Carbon::parse($expiresAt, config('app.timezone', 'Europe/Rome')) : null);

        return ApartmentUserAccess::updateOrCreate(
            ['apartment_id' => $apartment->getKey(), 'user_id' => $granteeId],
            ['granted_by' => $actor->getKey(), 'expires_at' => $expires],
        );
    }

    public function revoke(User $actor, ApartmentUserAccess $grant): void
    {
        $this->authorizeManage($actor);
        $grant->delete();
    }

    private function authorizeManage(User $actor): void
    {
        if (! $this->canManage($actor)) {
            throw new AuthorizationException('У пользователя нет права управлять доступом к квартирам.');
        }
    }
}
