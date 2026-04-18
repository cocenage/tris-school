<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class TelegramAuthService
{
    public function loginOrCreate(array $telegramUser): User
    {
        $user = User::query()->firstOrNew([
            'telegram_id' => $telegramUser['telegram_id'],
        ]);

        $isNew = ! $user->exists;

        $firstName = $telegramUser['first_name'] ?? null;
        $lastName = $telegramUser['last_name'] ?? null;
        $fullName = trim(collect([$firstName, $lastName])->filter()->implode(' '));

        $user->telegram_username = $telegramUser['username'] ?? $user->telegram_username;
        $user->telegram_first_name = $firstName ?? $user->telegram_first_name;
        $user->telegram_last_name = $lastName ?? $user->telegram_last_name;
        $user->telegram_photo_url = $telegramUser['photo_url'] ?? $user->telegram_photo_url;
        $user->telegram_last_auth_at = now();
        $user->telegram_login_source = $telegramUser['source'] ?? null;
        $user->last_login_at = now();

        if ($isNew) {
            $user->name = $fullName ?: ('Telegram User '.$telegramUser['telegram_id']);
            $user->role = 'cleaner';
            $user->status = 'pending';
            $user->is_active = true;
            $user->password = Str::password(32);
        } elseif (blank($user->name) && $fullName !== '') {
            $user->name = $fullName;
        }

        $user->save();

        Auth::login($user, true);
        request()->session()->regenerate();

        return $user;
    }

    public function markWriteAccessGranted(User $user): void
    {
        if (! $user->telegram_write_access_granted_at) {
            $user->telegram_write_access_granted_at = now();
            $user->save();
        }
    }

    public function redirectRouteFor(User $user): string
    {
        return match ($user->status) {
            'approved' => route('page-home'),
            'pending' => route('access.pending'),
            'rejected' => route('access.rejected'),
            default => route('landing.page'),
        };
    }
}