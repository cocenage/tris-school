<?php

namespace App\Http\Controllers;

use App\Models\ControlResponse;
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
        $redirect = $authService->redirectRouteFor($user);

        if (preg_match('/^control_result_([1-9][0-9]*)$/D', (string) ($telegramUser['start_param'] ?? ''), $match)) {
            abort_unless($user->status === 'approved' && $user->is_active
                && in_array($user->role, ['cleaner', 'supervisor', 'admin'], true), 403);

            $controlResponse = ControlResponse::query()
                ->whereKey((int) $match[1])
                ->where('status', ControlResponse::STATUS_SENT)
                ->where(fn ($query) => $query
                    ->where('cleaner_id', $user->id)
                    ->orWhere('supervisor_id', $user->id))
                ->first();

            abort_unless($controlResponse, 403);
            $redirect = route('page-profile.checks.result', $controlResponse);
        }

        return response()->json([
            'ok' => true,
            'redirect' => $redirect,
            'user' => [
                'id' => $user->id,
                'telegram_id' => $user->telegram_id,
                'status' => $user->status,
            ],
        ]);
    }
}
