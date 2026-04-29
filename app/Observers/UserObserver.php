<?php

namespace App\Observers;

use App\Models\User;
use App\Services\TelegramUserNotificationService;
use Illuminate\Support\Facades\Auth;

class UserObserver
{
    public function updated(User $user): void
    {
        if (! $user->wasChanged('status')) {
            return;
        }

        if ($user->status !== 'approved') {
            return;
        }

        if ($user->getOriginal('status') === 'approved') {
            return;
        }

        $updates = [];

        if (! $user->approved_at) {
            $updates['approved_at'] = now();
        }

        if (! $user->approved_by && Auth::check()) {
            $updates['approved_by'] = Auth::id();
        }

        if ($updates !== []) {
            $user->forceFill($updates)->saveQuietly();
        }

        app(TelegramUserNotificationService::class)->accessApproved($user->fresh());
    }
}