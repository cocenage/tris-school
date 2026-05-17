<?php

namespace App\Http\Controllers;

use App\Services\Telegram\TelegramWorkMessageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TelegramWorkWebhookController extends Controller
{
    public function __invoke(
        Request $request,
        string $secret,
        TelegramWorkMessageService $workMessageService
    ): JsonResponse {
        abort_unless(
            hash_equals((string) config('services.telegram.work_webhook_secret'), $secret),
            403
        );

        $workMessageService->handleUpdate($request->all());

        return response()->json(['ok' => true]);
    }
}