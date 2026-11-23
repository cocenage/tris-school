<?php

namespace App\Services\Telegram;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The common acknowledgement path for all Work bot callback buttons.
 * It is called after durable actions have completed, never speculatively.
 */
class TelegramWorkCallbackFeedback
{
    public function answer(array $callback, string $text, string $url, ?int $callbackStartedAt = null): bool
    {
        $callbackId = $callback['id'] ?? null;
        if (! is_string($callbackId) || $callbackId === '') {
            return false;
        }

        $beganAt = hrtime(true);
        $type = explode(':', (string) ($callback['data'] ?? ''), 2)[0];
        $accepted = false;
        $failure = null;
        $status = null;

        try {
            $response = Http::withOptions([
                'force_ip_resolve' => config('services.telegram.ip_resolve', 'v6'),
            ])->timeout(2)->connectTimeout(1)->post($url, [
                'callback_query_id' => $callbackId,
                'text' => mb_substr($text, 0, 200),
                'cache_time' => 0,
            ]);

            $status = $response->status();
            $accepted = $response->successful() && data_get($response->json(), 'ok') !== false;
            if (! $accepted) {
                $failure = 'telegram_rejected';
            }
        } catch (Throwable $exception) {
            $failure = class_basename($exception);
        }

        $ackMs = round((hrtime(true) - $beganAt) / 1_000_000, 2);
        $totalMs = $callbackStartedAt === null ? null : round((hrtime(true) - $callbackStartedAt) / 1_000_000, 2);
        $metadata = [
            'callback_type' => $type,
            'ack_duration_ms' => $ackMs,
            'callback_duration_ms' => $totalMs,
            'ack_accepted' => $accepted,
        ];

        if (! $accepted) {
            Log::warning('Telegram Work callback acknowledgement failed.', $metadata + [
                'http_status' => $status,
                'reason' => $failure,
            ]);
        } else {
            Log::info('Telegram Work callback acknowledged.', $metadata);
            if ($totalMs !== null && $totalMs > 1500) {
                Log::warning('Telegram Work callback was slow.', $metadata);
            }
        }

        return $accepted;
    }
}
