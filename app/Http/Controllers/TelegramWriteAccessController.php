<?php

namespace App\Http\Controllers;

use App\Services\TelegramAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TelegramWriteAccessController extends Controller
{
    public function __invoke(
        Request $request,
        TelegramAuthService $authService
    ): JsonResponse {
        $validated = $request->validate([
            'granted' => ['required', 'boolean'],
        ]);

        if (! auth()->check()) {
            return response()->json([
                'ok' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        if ($validated['granted']) {
            $authService->markWriteAccessGranted(auth()->user());
        }

        return response()->json([
            'ok' => true,
            'granted' => (bool) $validated['granted'],
        ]);
    }
}