<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\TelegramMiniAppService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class TelegramAuthController extends Controller
{
    public function __invoke(Request $request, TelegramMiniAppService $telegram)
    {
        $request->validate([
            'init_data' => ['required', 'string'],
        ]);

        $telegramUser = $telegram->validate($request->string('init_data')->toString());

        $name = trim(
            collect([
                $telegramUser['first_name'],
                $telegramUser['last_name'],
            ])->filter()->implode(' ')
        );

        $user = User::query()->firstOrCreate(
            ['telegram_id' => $telegramUser['telegram_id']],
            [
                'name' => $name ?: ('Telegram User ' . $telegramUser['telegram_id']),
                'telegram_username' => $telegramUser['username'],
                'telegram_photo_url' => $telegramUser['photo_url'],
                'status' => 'pending',
            ]
        );

        $user->update([
            'name' => $name ?: $user->name,
            'telegram_username' => $telegramUser['username'],
            'telegram_photo_url' => $telegramUser['photo_url'],
            'last_login_at' => now(),
        ]);

        if ($user->status === 'rejected') {
            return response()->json([
                'status' => 'rejected',
                'redirect' => route('access.rejected'),
            ]);
        }

        if ($user->status !== 'approved' || ! $user->role) {
            return response()->json([
                'status' => 'pending',
                'redirect' => route('access.pending'),
            ]);
        }

        Auth::login($user, true);
        $request->session()->regenerate();

        return response()->json([
            'status' => 'approved',
            'redirect' => route('dashboard'),
        ]);
    }
}