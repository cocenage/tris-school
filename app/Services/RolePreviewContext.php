<?php

namespace App\Services;

use Illuminate\Support\Facades\Auth;

class RolePreviewContext
{
    public const SESSION_KEY = 'ui_role_preview';

    private const PREVIEW_ROLES = ['supervisor', 'cleaner'];

    public function actualRole(): ?string
    {
        return Auth::user()?->role;
    }

    public function previewRole(): ?string
    {
        if (! $this->isAuthorizedAdmin()) {
            if (session()->has(self::SESSION_KEY)) {
                session()->forget(self::SESSION_KEY);
            }

            return null;
        }

        $role = session()->get(self::SESSION_KEY);

        if (! in_array($role, self::PREVIEW_ROLES, true)) {
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
        abort_unless($this->isAuthorizedAdmin(), 403);

        if ($role === 'admin') {
            $this->clear();

            return;
        }

        abort_unless(in_array($role, self::PREVIEW_ROLES, true), 422);

        session()->put(self::SESSION_KEY, $role);
    }

    public function clear(): void
    {
        abort_unless($this->isAuthorizedAdmin(), 403);

        session()->forget(self::SESSION_KEY);
    }

    public function assertWritable(): void
    {
        abort_if($this->isActive(), 403, 'Действия недоступны в режиме предпросмотра.');
    }

    private function isAuthorizedAdmin(): bool
    {
        $user = Auth::user();

        return $user?->role === 'admin' && $user->status === 'approved';
    }
}
