<?php

namespace App\Http\Controllers;

use App\Services\Telegram\TelegramScheduledInboundProcessor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class TelegramScheduledWebhookController extends Controller
{
    public function __invoke(Request $request, string $secret, TelegramScheduledInboundProcessor $processor)
    {
        $expected = config('services.telegram.scheduled_webhook_secret');
        abort_unless(filled($expected) && hash_equals((string) $expected, $secret), 403);
        try {
            return response()->json($processor->process($request->all()));
        } catch (\Throwable $error) {
            Log::warning('Scheduled control webhook failed', ['exception' => class_basename($error)]);

            return response()->json(['ok' => false], 500);
        }
    }
}
