<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\TelegramAvatarService;
use App\Services\TelegramMiniAppService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class TelegramAuthController extends Controller
{
    public function __invoke(
        Request $request,
        TelegramMiniAppService $telegram,
        TelegramAvatarService $avatarService
    ) {
        $request->validate([
            'init_data' => ['required', 'string'],
        ]);

        $telegramUser = $telegram->validate($request->string('init_data')->toString());

        $name = trim(
            collect([
                $telegramUser['first_name'] ?? null,
                $telegramUser['last_name'] ?? null,
            ])->filter()->implode(' ')
        );

        $safePhotoUrl = $avatarService->sanitizeMiniAppPhotoUrl(
            $telegramUser['photo_url'] ?? null
        );

        $user = User::query()->firstOrCreate(
            ['telegram_id' => $telegramUser['telegram_id']],
            [
                'name' => $name ?: ('Telegram User ' . $telegramUser['telegram_id']),
                'telegram_username' => $telegramUser['username'],
                'telegram_photo_url' => $safePhotoUrl,
                'status' => 'pending',
            ]
        );

        $user->update([
            'name' => $name ?: $user->name,
            'telegram_username' => $telegramUser['username'],
            'telegram_photo_url' => $safePhotoUrl,
        ]);

        $avatarPath = $avatarService->downloadUserAvatar($telegramUser['telegram_id']);

        if ($avatarPath) {
            $user->update([
                'telegram_avatar_path' => $avatarPath,
            ]);
        }

        Auth::login($user);

        return response()->json([
            'redirect' => route(match ($user->status) {
                'approved' => 'page-home',
                'pending' => 'access.pending',
                'rejected' => 'access.rejected',
                default => 'landing',
            }),
        ]);
    }
}