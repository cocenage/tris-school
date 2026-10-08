<?php

namespace App\Services\Telegram;

use App\Models\TelegramScheduledMessageDelivery;
use Carbon\CarbonImmutable;

class TelegramScheduledControlStatistics
{
    public function delivery(TelegramScheduledMessageDelivery $delivery, ?CarbonImmutable $summaryCutoff = null): array
    {
        $responses = $delivery->responses->sortBy(fn ($response) => $response->getRawOriginal('responded_at').'|'.str_pad((string) $response->id, 20, '0', STR_PAD_LEFT));
        $categories = array_fill_keys(['confirmed', 'problem', 'partial', 'unclear'], 0);
        $firstByResponder = [];
        $allLatencies = [];
        $unidentified = 0;
        foreach ($responses as $response) {
            $categories[$response->classification] = ($categories[$response->classification] ?? 0) + 1;
            $identity = $response->user_id !== null ? 'user:'.$response->user_id
                : ($response->telegram_user_id !== null ? 'telegram:'.$response->telegram_user_id : null);
            if ($identity === null) {
                $unidentified++;
            } elseif (! array_key_exists($identity, $firstByResponder)) {
                $firstByResponder[$identity] = $response->response_latency_seconds;
            }
            if ($response->response_latency_seconds !== null) {
                $allLatencies[] = $response->response_latency_seconds;
            }
        }
        $latencies = array_values(array_filter($firstByResponder, fn ($latency) => $latency !== null));
        if ($responses->isNotEmpty()) {
            $result = collect(['problem', 'partial', 'confirmed', 'unclear'])->first(fn ($category) => $categories[$category] > 0);
        } elseif ($delivery->status !== 'sent' || ! $delivery->sent_at) {
            $result = 'pending';
        } else {
            $expires = CarbonImmutable::parse($delivery->getRawOriginal('sent_at'), config('app.timezone', 'Europe/Rome'))
                ->addMinutes(max(1, (int) config('services.telegram.scheduled_response_window_minutes', 60)));
            $due = $summaryCutoff !== null && $summaryCutoff->lt($expires) ? $summaryCutoff : $expires;
            $result = CarbonImmutable::now()->lt($due) ? 'pending' : 'no_response';
        }

        return [
            'response_count' => $responses->count(), 'unique_responder_count' => count($firstByResponder),
            'unidentified_response_messages' => $unidentified, 'classification_counts' => $categories,
            'first_response_latency_seconds' => $allLatencies === [] ? null : min($allLatencies),
            'median_response_latency_seconds' => $this->median($latencies),
            'latency_samples' => $latencies, 'result' => $result,
        ];
    }

    public function median(array $values): ?float
    {
        if ($values === []) {
            return null;
        }
        sort($values, SORT_NUMERIC);
        $count = count($values);
        $middle = intdiv($count, 2);

        return $count % 2 ? (float) $values[$middle] : ($values[$middle - 1] + $values[$middle]) / 2;
    }
}
