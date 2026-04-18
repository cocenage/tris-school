<?php

namespace App\Http\Controllers;

use App\Services\TelegramAuthService;
use App\Services\TelegramMiniAppService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TelegramAuthController extends Controller
{
    public function __invoke(
        Request $request,
        TelegramMiniAppService $miniAppService,
        TelegramAuthService $authService
    ): JsonResponse {
        $validated = $request->validate([
            'init_data' => ['required', 'string'],
        ]);

        $telegramUser = $miniAppService->validate($validated['init_data']);

        $user = $authService->loginOrCreate($telegramUser);

        return response()->json([
            'ok' => true,
            'redirect' => $authService->redirectRouteFor($user),
            'user' => [
                'id' => $user->id,
                'telegram_id' => $user->telegram_id,
                'status' => $user->status,
            ],
        ]);
    }
}