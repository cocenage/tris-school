<?php

namespace App\Services\Telegram;

use App\Models\TelegramMessage;
use App\Models\TelegramScheduledMessageDelivery;
use App\Models\TelegramScheduledMessageResponse;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

class TelegramScheduledControlResponseService
{
    public function __construct(private ScheduledControlResponseClassifier $classifier) {}

    /** @return array{status: 'matched'|'unmatched'|'ambiguous', delivery: ?TelegramScheduledMessageDelivery, candidate_delivery_ids?: list<int>} */
    public function resolveDelivery(array $message): array
    {
        $replyId = data_get($message, 'reply_to_message.message_id');
        $chatId = data_get($message, 'chat.id');
        $messageId = $message['message_id'] ?? null;
        $hasReplyObject = array_key_exists('reply_to_message', $message) && $message['reply_to_message'] !== null;
        if (! is_numeric($chatId) || ! is_numeric($messageId) || ! is_numeric(data_get($message, 'from.id')) || data_get($message, 'from.is_bot', false)) {
            return ['status' => 'unmatched', 'delivery' => null];
        }

        if ($hasReplyObject) {
            if (! is_numeric($replyId)) {
                return ['status' => 'unmatched', 'delivery' => null];
            }
        } else {
            if (! is_numeric($message['date'] ?? null)) {
                return ['status' => 'unmatched', 'delivery' => null];
            }

            return $this->resolveWindow($message, (string) $chatId, (string) $messageId);
        }
        $threadId = isset($message['message_thread_id']) ? (string) $message['message_thread_id'] : null;
        $candidates = TelegramScheduledMessageDelivery::query()->where('status', 'sent')->whereNotNull('sent_at')
            ->whereNotNull('control_type')->where('control_type', '!=', '')
            ->where('chat_id', (string) $chatId)->where('telegram_message_id', $replyId)
            ->where('message_thread_id', $threadId)->limit(2)->get();

        return match ($candidates->count()) {
            0 => ['status' => 'unmatched', 'delivery' => null],
            1 => ['status' => 'matched', 'delivery' => $candidates->first()],
            default => ['status' => 'ambiguous', 'delivery' => null],
        };
    }

    public function isSupportedControlDestination(array $message): bool
    {
        $chatId = data_get($message, 'chat.id');
        if (! is_numeric($chatId) || ! is_numeric(data_get($message, 'from.id')) || data_get($message, 'from.is_bot', false)
            || ! in_array(data_get($message, 'chat.type'), ['group', 'supergroup'], true)) {
            return false;
        }

        $threadId = isset($message['message_thread_id']) ? (string) $message['message_thread_id'] : null;

        return TelegramScheduledMessageDelivery::query()->where('status', 'sent')->whereNotNull('sent_at')
            ->whereNotNull('control_type')->where('control_type', '!=', '')
            ->where('chat_id', (string) $chatId)->where('message_thread_id', $threadId)->exists();
    }

    /** @return array{status: 'matched'|'unmatched'|'ambiguous', delivery: ?TelegramScheduledMessageDelivery, candidate_delivery_ids?: list<int>} */
    private function resolveWindow(array $message, string $chatId, string $messageId): array
    {
        $timezone = config('app.timezone', 'Europe/Rome');
        $respondedAt = CarbonImmutable::createFromTimestamp((int) $message['date'], $timezone);
        $threadId = isset($message['message_thread_id']) ? (string) $message['message_thread_id'] : null;
        $text = (string) ($message['text'] ?? $message['caption'] ?? '');

        if ($this->classify($text)['classification'] === 'unclear') {
            return ['status' => 'unmatched', 'delivery' => null];
        }

        $deliveries = TelegramScheduledMessageDelivery::query()->where('status', 'sent')->whereNotNull('sent_at')
            ->whereNotNull('telegram_message_id')->whereIn('control_type', array_keys(ScheduledControlTypes::LABELS))
            ->where('chat_id', $chatId)->where('message_thread_id', $threadId)
            ->orderBy('sent_at')->orderBy('id')->get();

        if ($deliveries->contains(fn (TelegramScheduledMessageDelivery $delivery): bool => (string) $delivery->telegram_message_id === $messageId)) {
            return ['status' => 'unmatched', 'delivery' => null];
        }

        $windows = [];
        $groups = $deliveries->groupBy(fn (TelegramScheduledMessageDelivery $delivery): string => (string) $delivery->getRawOriginal('sent_at'));
        $starts = $groups->keys()->sort()->values();

        foreach ($starts as $index => $start) {
            $startAt = CarbonImmutable::parse($start, $timezone);
            $nextStart = $starts->get($index + 1);
            $nextAt = $nextStart === null ? null : CarbonImmutable::parse($nextStart, $timezone);
            $windowEnd = $startAt->addMinutes(max(1, (int) config('services.telegram.scheduled_response_window_minutes', 60)));

            if ($respondedAt->lte($startAt) || $respondedAt->gte($windowEnd)
                || ($nextAt !== null && $respondedAt->gte($nextAt))) {
                continue;
            }

            $windows = $groups->get($start, collect())->all();
            break;
        }

        if (count($windows) !== 1) {
            return [
                'status' => count($windows) > 1 ? 'ambiguous' : 'unmatched',
                'delivery' => null,
                'candidate_delivery_ids' => array_map(fn (TelegramScheduledMessageDelivery $delivery): int => $delivery->id, $windows),
            ];
        }

        return ['status' => 'matched', 'delivery' => $windows[0]];
    }

    public function capture(TelegramMessage $stored, array $message): ?TelegramScheduledMessageResponse
    {
        $match = $this->resolveDelivery($message);
        if ($match['status'] !== 'matched') {
            // Ordinary chat/discussion replies are not controls and do not spam logs.
            return null;
        }
        $delivery = $match['delivery'];
        $replyId = data_get($message, 'reply_to_message.message_id');
        $chatId = data_get($message, 'chat.id');
        $messageId = $message['message_id'];
        $threadId = isset($message['message_thread_id']) ? (string) $message['message_thread_id'] : null;
        $text = (string) ($message['text'] ?? $message['caption'] ?? '');
        $result = $this->classify($text);
        $timezone = config('app.timezone', 'Europe/Rome');
        $respondedAt = isset($message['date']) ? CarbonImmutable::createFromTimestamp((int) $message['date'], $timezone) : CarbonImmutable::parse($stored->getRawOriginal('sent_at'), $timezone);
        $sentAt = CarbonImmutable::parse($delivery->getRawOriginal('sent_at'), $timezone);
        $authorId = data_get($message, 'from.id');
        $telegramUser = $stored->telegramUser;
        $userId = $telegramUser?->linked_user_id;
        if ($userId === null && $authorId !== null) {
            $userId = User::query()->where('telegram_id', (string) $authorId)->value('id');
        }
        $existing = TelegramScheduledMessageResponse::query()->where('chat_id', (string) $chatId)->where('telegram_message_id', (string) $messageId)->first();
        // Edited replies update the same record; ordinary duplicate updates are inert.
        if ($existing && ! isset($message['edit_date'])) {
            Log::debug('scheduled_control_response_duplicate', ['response_id' => $existing->id]);

            return $existing;
        }
        $values = [
            'delivery_id' => $delivery->id, 'telegram_message_record_id' => $stored->id,
            'message_thread_id' => $threadId, 'reply_to_message_id' => $replyId === null ? null : (string) $replyId,
            'telegram_user_id' => $authorId === null ? null : (string) $authorId, 'user_id' => $userId,
            'author_name' => $telegramUser?->full_name ?: trim((string) data_get($message, 'from.first_name').' '.(string) data_get($message, 'from.last_name')) ?: null,
            'username' => data_get($message, 'from.username'), 'text' => $text,
            'responded_at' => $respondedAt,
            'response_latency_seconds' => $respondedAt->lt($sentAt) ? null : (int) $sentAt->diffInSeconds($respondedAt),
            'classification' => $result['classification'], 'classification_reason' => $result['reason'],
        ];
        $response = $existing ?: TelegramScheduledMessageResponse::firstOrCreate([
            'chat_id' => (string) $chatId, 'telegram_message_id' => (string) $messageId,
        ], $values);
        if ($existing) {
            $response->update($values);
        }
        Log::info('scheduled_control_response_linked', ['response_id' => $response->id, 'delivery_id' => $delivery->id]);

        return $response;
    }

    private function classify(string $text): array
    {
        try {
            return $this->classifier->classify($text);
        } catch (\Throwable) {
            return ['classification' => 'unclear', 'reason' => 'classification_failed'];
        }
    }
}
