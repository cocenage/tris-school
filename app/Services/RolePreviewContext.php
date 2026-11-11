<?php

namespace App\Services;

use Illuminate\Support\Facades\Auth;

class RolePreviewContext
{
    public const SESSION_KEY = 'ui_role_preview';

    private const PREVIEW_ROLES = [
        'admin' => ['supervisor', 'cleaner'],
        'supervisor' => ['cleaner'],
    ];

    public function actualRole(): ?string
    {
        return Auth::user()?->role;
    }

    public function previewRole(): ?string
    {
        if (! $this->canManagePreview()) {
            if (session()->has(self::SESSION_KEY)) {
                session()->forget(self::SESSION_KEY);
            }

            return null;
        }

        $role = session()->get(self::SESSION_KEY);

        if (! in_array($role, $this->previewRolesForCurrentUser(), true)) {
            if (session()->has(self::SESSION_KEY)) {
                session()->forget(self::SESSION_KEY);
            }

            return null;
        }

        return $role;
    }

    public function effectiveUiRole(): ?string
    {
        return $this->previewRole() ?? $this->actualRole();
    }

    public function isActive(): bool
    {
        return $this->previewRole() !== null;
    }

    public function start(string $role): void
    {
        abort_unless($this->canManagePreview(), 403);

        if ($role === $this->actualRole()) {
            $this->clear();

            return;
        }

        abort_unless(in_array($role, $this->previewRolesForCurrentUser(), true), 422);

        session()->put(self::SESSION_KEY, $role);
    }

    public function clear(): void
    {
        abort_unless($this->canManagePreview(), 403);

        session()->forget(self::SESSION_KEY);
    }

    public function availableRoles(): array
    {
        if (! $this->canManagePreview()) {
            return [];
        }

        return [$this->actualRole(), ...$this->previewRolesForCurrentUser()];
    }

    public function canManagePreview(): bool
    {
        $user = Auth::user();

        return $user?->status === 'approved'
            && array_key_exists($user->role, self::PREVIEW_ROLES);
    }

    public function assertWritable(): void
    {
        abort_if($this->isActive(), 403, 'Действия недоступны в режиме предпросмотра.');
    }

    private function previewRolesForCurrentUser(): array
    {
        return self::PREVIEW_ROLES[$this->actualRole()] ?? [];
    }
}
