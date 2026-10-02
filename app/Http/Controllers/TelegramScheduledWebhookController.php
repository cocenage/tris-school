<?php

namespace App\Http\Controllers;

use App\Models\TelegramScheduledMessageDelivery;
use App\Services\Telegram\TelegramScheduledControlResponseService;
use App\Services\Telegram\TelegramUpdateIngestService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class TelegramScheduledWebhookController extends Controller
{
    public function __invoke(Request $request, string $secret, TelegramUpdateIngestService $ingest, TelegramScheduledControlResponseService $responses)
    {
        $expected = config('services.telegram.scheduled_webhook_secret');
        abort_unless(filled($expected) && hash_equals((string) $expected, $secret), 403);
        $update = $request->all();
        // This bot has no assistant/callback/day-off behavior.
        $message = $update['message'] ?? $update['edited_message'] ?? null;
        if (! $message || ! in_array(data_get($message, 'chat.type'), ['group', 'supergroup'], true)) {
            return response()->json(['ok' => true, 'skipped' => 'no_group_message']);
        }
        $chatId = (string) data_get($message, 'chat.id');
        $allowed = config('services.telegram.scheduled_webhook_allowed_chat_ids', []);
        $permitted = $allowed !== [] ? in_array($chatId, $allowed, true)
            : TelegramScheduledMessageDelivery::query()->where('chat_id', $chatId)->exists();
        if (! $permitted) {
            return response()->json(['ok' => true, 'skipped' => 'chat_not_allowed']);
        }
        try {
            $stored = $ingest->ingest($update);
            if ($stored) {
                $responses->capture($stored, $message);
            }

            return response()->json(['ok' => true]);
        } catch (\Throwable $error) {
            Log::warning('Scheduled control webhook failed', ['exception' => class_basename($error)]);

            return response()->json(['ok' => false], 500);
        }
    }
}
