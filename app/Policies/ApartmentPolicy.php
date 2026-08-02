<?php

namespace App\Policies;

use App\Models\Apartment;
use App\Models\ApartmentInformationAttachment;
use App\Models\ApartmentInformationSection;
use App\Models\User;
use App\Services\Apartments\ApartmentAccessService;

class ApartmentPolicy
{
    public function viewAny(User $user): bool
    {
        return app(ApartmentAccessService::class)->visibleQuery($user)->exists();
    }

    public function view(User $user, Apartment $apartment): bool
    {
        return app(ApartmentAccessService::class)->canView($user, $apartment);
    }

    public function create(User $user): bool
    {
        return app(ApartmentAccessService::class)->canManage($user);
    }

    public function update(User $user, Apartment $apartment): bool
    {
        return app(ApartmentAccessService::class)->canManage($user);
    }

    public function delete(User $user, Apartment $apartment): bool
    {
        return app(ApartmentAccessService::class)->canManage($user);
    }

    public function manageInformation(User $user, Apartment $apartment): bool
    {
        return app(ApartmentAccessService::class)->canManage($user);
    }

    public function manageAccess(User $user, Apartment $apartment): bool
    {
        return app(ApartmentAccessService::class)->canManage($user);
    }

    public function viewSection(User $user, ApartmentInformationSection $section): bool
    {
        return $section->apartment && $this->view($user, $section->apartment);
    }

    public function updateSection(User $user, ApartmentInformationSection $section): bool
    {
        return $section->apartment && $this->manageInformation($user, $section->apartment);
    }

    public function viewAttachment(User $user, ApartmentInformationAttachment $attachment): bool
    {
        return $attachment->apartment && $this->view($user, $attachment->apartment);
    }

    public function manageAttachment(User $user, ApartmentInformationAttachment $attachment): bool
    {
        return $attachment->apartment && $this->manageInformation($user, $attachment->apartment);
    }
}
