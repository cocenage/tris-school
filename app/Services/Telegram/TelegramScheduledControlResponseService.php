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

    public function capture(TelegramMessage $stored, array $message): ?TelegramScheduledMessageResponse
    {
        $replyId = data_get($message, 'reply_to_message.message_id');
        $chatId = data_get($message, 'chat.id');
        $messageId = $message['message_id'] ?? null;
        if (! is_numeric($replyId) || ! is_numeric($chatId) || ! is_numeric($messageId) || data_get($message, 'from.is_bot', false)) {
            return null;
        }
        $threadId = isset($message['message_thread_id']) ? (string) $message['message_thread_id'] : null;
        $candidates = TelegramScheduledMessageDelivery::query()->where('status', 'sent')->whereNotNull('sent_at')
            ->where('chat_id', (string) $chatId)->where('telegram_message_id', $replyId)
            ->where('message_thread_id', $threadId)->limit(2)->get();
        if ($candidates->count() !== 1) {
            // Ordinary chat/discussion replies are not controls and do not spam logs.
            return null;
        }
        $delivery = $candidates->first();
        $text = (string) ($message['text'] ?? $message['caption'] ?? '');
        try {
            $result = $this->classifier->classify($text);
        } catch (\Throwable) {
            $result = ['classification' => 'unclear', 'reason' => 'classification_failed'];
        }
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
            'message_thread_id' => $threadId, 'reply_to_message_id' => (string) $replyId,
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
}
