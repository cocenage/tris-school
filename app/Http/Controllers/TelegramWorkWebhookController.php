<?php

namespace App\Http\Controllers;

use App\Services\Telegram\TelegramUpdateIngestService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class TelegramWorkWebhookController extends Controller
{
    public function __invoke(
        Request $request,
        string $secret,
        TelegramUpdateIngestService $ingestService
    ) {
        if ($secret !== config('services.telegram.work_webhook_secret')) {
            abort(403);
        }

        $update = $request->all();

        $message = $update['message']
            ?? $update['edited_message']
            ?? $update['channel_post']
            ?? null;

        if (!$message) {
            return response()->json(['ok' => true, 'skipped' => 'no_message']);
        }

        $chatId = (string) data_get($message, 'chat.id');

        $allowedChatIds = config('services.telegram.work_allowed_chat_ids', []);

        if (!empty($allowedChatIds) && !in_array($chatId, $allowedChatIds, true)) {
            return response()->json([
                'ok' => true,
                'skipped' => 'chat_not_allowed',
                'chat_id' => $chatId,
            ]);
        }

        try {
            $savedMessage = $ingestService->ingest($update);

            return response()->json([
                'ok' => true,
                'message_id' => $savedMessage?->id,
            ]);
        } catch (\Throwable $e) {
            Log::error('Telegram work webhook failed', [
                'error' => $e->getMessage(),
                'update' => $update,
            ]);

            return response()->json(['ok' => false], 500);
        }
    }
}