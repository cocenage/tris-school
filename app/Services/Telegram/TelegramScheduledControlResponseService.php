<?php

namespace App\Services\Telegram;

use App\Models\TelegramMessage;
use App\Models\TelegramScheduledMessageDelivery;
use App\Models\TelegramScheduledMessageResponse;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
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
        $result = str_contains($text, '?')
            ? ['classification' => 'unclear', 'reason' => 'question_not_confirmation']
            : $this->classify($text);
        $districtKey = $this->districtFromText($text);
        if ($delivery->bot_type !== 'work' && $delivery->control_type === ScheduledControlTypes::CLOSING_PAYMENTS) {
            $finalResponse = $delivery->responses()->first();
            if ($finalResponse !== null) {
                return $finalResponse;
            }
        }
        $timezone = config('app.timezone', 'Europe/Rome');
        $respondedAt = isset($message['date'])
            ? CarbonImmutable::createFromTimestamp((int) $message['date'], 'UTC')->setTimezone($timezone)
            : ($stored->sent_at
                ? CarbonImmutable::instance($stored->sent_at)
                : CarbonImmutable::now($timezone));
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
            'district_key' => $districtKey,
            'response_kind' => $result['classification'] === 'unclear' ? 'comment' : 'text_answer',
        ];
        $response = $existing ?: TelegramScheduledMessageResponse::firstOrCreate([
            'chat_id' => (string) $chatId, 'telegram_message_id' => (string) $messageId,
        ], $values);
        if ($existing) {
            if ($existing->text !== $text) {
                $values['response_history'] = [...($existing->response_history ?? []), [
                    'text' => $existing->text,
                    'classification' => $existing->classification,
                    'responded_at' => $existing->responded_at?->toIso8601String(),
                ]];
            }
            $response->update($values);
        }
        Log::info('scheduled_control_response_linked', ['response_id' => $response->id, 'delivery_id' => $delivery->id]);

        return $response;
    }

    /** @return array{status: 'saved'|'already_answered'|'unmatched'|'invalid', delivery: ?TelegramScheduledMessageDelivery, response: ?TelegramScheduledMessageResponse, action: ?string} */
    public function captureCallback(array $callbackQuery): array
    {
        $callbackId = $callbackQuery['id'] ?? null;
        $data = $callbackQuery['data'] ?? null;
        $message = $callbackQuery['message'] ?? [];
        $chatId = data_get($message, 'chat.id');
        $messageId = data_get($message, 'message_id');
        $threadId = isset($message['message_thread_id']) ? (string) $message['message_thread_id'] : null;
        $telegramUserId = data_get($callbackQuery, 'from.id');

        $districtAnswer = is_string($data) && preg_match('/^sc:(\d+):([a-z]+):(yes|no)$/', $data, $districtParts) === 1;
        $legacyAnswer = is_string($data) && preg_match('/^sc:(\d+):(yes|no)$/', $data, $parts) === 1;
        if (! is_string($callbackId) || $callbackId === '' || (! $districtAnswer && ! $legacyAnswer)
            || ! is_numeric($chatId) || ! is_numeric($messageId) || ! is_numeric($telegramUserId)
            || data_get($callbackQuery, 'from.is_bot', false)
            || ! in_array(data_get($message, 'chat.type'), ['group', 'supergroup'], true)) {
            return ['status' => 'invalid', 'delivery' => null, 'response' => null, 'action' => null];
        }

        if ($districtAnswer) {
            return $this->captureDistrictCallback($callbackQuery, $districtParts);
        }

        $action = $parts[2];
        $deliveryQuery = TelegramScheduledMessageDelivery::query()
            ->whereKey((int) $parts[1])
            ->where('status', 'sent')
            ->where('chat_id', (string) $chatId)
            ->where('telegram_message_id', (string) $messageId);
        if (array_key_exists('message_thread_id', $message)) {
            $deliveryQuery->where('message_thread_id', $threadId);
        }
        $delivery = $deliveryQuery->first();

        if ($delivery === null || ! ScheduledControlTypes::supportsInlineAnswer($delivery->control_type)) {
            return ['status' => 'unmatched', 'delivery' => null, 'response' => null, 'action' => $action];
        }

        $text = $action === 'yes' ? 'Да' : 'Нет';
        $classification = $this->classify($text);
        $respondedAt = CarbonImmutable::now(config('app.timezone', 'Europe/Rome'));
        $sentAt = CarbonImmutable::parse($delivery->getRawOriginal('sent_at'), config('app.timezone', 'Europe/Rome'));
        $user = User::query()->where('telegram_id', (string) $telegramUserId)->first();
        $authorName = trim((string) data_get($callbackQuery, 'from.first_name').' '.(string) data_get($callbackQuery, 'from.last_name'));

        $username = data_get($callbackQuery, 'from.username');

        $result = DB::transaction(function () use ($delivery, $callbackId, $telegramUserId, $user, $authorName, $username, $text, $classification, $respondedAt, $sentAt, $action): array {
            TelegramScheduledMessageDelivery::query()->whereKey($delivery->getKey())->lockForUpdate()->first();
            $responses = $delivery->responses()
                ->where('telegram_user_id', (string) $telegramUserId)
                ->orderByDesc('responded_at')
                ->orderByDesc('id')
                ->lockForUpdate()
                ->get();
            $existing = $responses->first();

            if ($existing?->telegram_callback_query_id === $callbackId) {
                return ['status' => 'already_answered', 'response' => $existing];
            }

            if ($delivery->control_type === ScheduledControlTypes::CLOSING_PAYMENTS
                && $delivery->responses()->lockForUpdate()->exists()) {
                return ['status' => 'already_answered', 'response' => $delivery->responses()->latest('id')->first()];
            }

            if ($delivery->control_type === ScheduledControlTypes::CLOSING_CLEANINGS
                && $existing !== null
                && $existing->classification === 'confirmed') {
                return ['status' => 'already_answered', 'response' => $existing];
            }

            if ($delivery->control_type === ScheduledControlTypes::CLOSING_CLEANINGS
                && $existing !== null
                && $action === 'no'
                && $existing->classification === 'problem') {
                return ['status' => 'already_answered', 'response' => $existing];
            }

            $values = [
                'delivery_id' => $delivery->id,
                'telegram_message_record_id' => null,
                'telegram_message_id' => null,
                'telegram_callback_query_id' => $callbackId,
                'chat_id' => (string) $delivery->chat_id,
                'message_thread_id' => $delivery->message_thread_id,
                'reply_to_message_id' => (string) $delivery->telegram_message_id,
                'telegram_user_id' => (string) $telegramUserId,
                'user_id' => $user?->id,
                'author_name' => $authorName !== '' ? $authorName : null,
                'username' => $username,
                'text' => $text,
                'responded_at' => $respondedAt,
                'response_latency_seconds' => $respondedAt->lt($sentAt) ? null : (int) $sentAt->diffInSeconds($respondedAt),
                'classification' => $classification['classification'],
                'classification_reason' => 'inline_button_'.$classification['reason'],
            ];

            if ($existing !== null) {
                // A cleaner's earlier negative answer remains editable to a positive answer.
                // Keep its original Telegram message linkage when it came from a text reply.
                $existing->fill(array_merge($values, [
                    'telegram_message_record_id' => $existing->telegram_message_record_id,
                    'telegram_message_id' => $existing->telegram_message_id,
                ]))->save();

                return ['status' => 'saved', 'response' => $existing->fresh()];
            }

            return [
                'status' => 'saved',
                'response' => TelegramScheduledMessageResponse::query()->create($values),
            ];
        });

        return [
            'status' => $result['status'],
            'delivery' => $delivery,
            'response' => $result['response'],
            'action' => $action,
        ];
    }

    /** @param array<int, string> $parts */
    private function captureDistrictCallback(array $callbackQuery, array $parts): array
    {
        $delivery = TelegramScheduledMessageDelivery::query()
            ->whereKey((int) $parts[1])
            ->where('status', 'sent')
            ->where('bot_type', 'work')
            ->where('chat_id', (string) data_get($callbackQuery, 'message.chat.id'))
            ->where('telegram_message_id', (string) data_get($callbackQuery, 'message.message_id'))
            ->when(array_key_exists('message_thread_id', $callbackQuery['message'] ?? []), fn ($query) => $query->where('message_thread_id', (string) data_get($callbackQuery, 'message.message_thread_id')))
            ->first();
        $districtKey = $parts[2];
        $action = $parts[3];

        if ($delivery === null || ! array_key_exists($districtKey, ScheduledControlTypes::DISTRICTS)
            || ! array_key_exists((string) $delivery->control_type, ScheduledControlTypes::LABELS)) {
            return ['status' => 'unmatched', 'delivery' => null, 'response' => null, 'action' => $action];
        }

        $timezone = config('app.timezone', 'Europe/Rome');
        $scheduledDay = CarbonImmutable::parse($delivery->getRawOriginal('scheduled_for'), $timezone)->toDateString();
        if ($scheduledDay !== CarbonImmutable::now($timezone)->toDateString()) {
            return ['status' => 'unmatched', 'delivery' => null, 'response' => null, 'action' => $action];
        }

        $telegramUserId = (string) data_get($callbackQuery, 'from.id');
        $callbackId = (string) $callbackQuery['id'];
        $user = User::query()->where('telegram_id', $telegramUserId)->first();
        $authorName = trim((string) data_get($callbackQuery, 'from.first_name').' '.(string) data_get($callbackQuery, 'from.last_name'));
        $respondedAt = CarbonImmutable::now(config('app.timezone', 'Europe/Rome'));
        $sentAt = CarbonImmutable::parse($delivery->getRawOriginal('sent_at'), config('app.timezone', 'Europe/Rome'));

        $response = DB::transaction(function () use ($delivery, $callbackId, $telegramUserId, $user, $authorName, $respondedAt, $sentAt, $districtKey, $action, $callbackQuery): TelegramScheduledMessageResponse {
            TelegramScheduledMessageDelivery::query()->whereKey($delivery->id)->lockForUpdate()->first();

            return TelegramScheduledMessageResponse::query()->firstOrCreate(
                ['telegram_callback_query_id' => $callbackId],
                [
                    'delivery_id' => $delivery->id,
                    'telegram_message_record_id' => null,
                    'telegram_message_id' => null,
                    'chat_id' => (string) $delivery->chat_id,
                    'message_thread_id' => $delivery->message_thread_id,
                    'reply_to_message_id' => (string) $delivery->telegram_message_id,
                    'telegram_user_id' => $telegramUserId,
                    'user_id' => $user?->id,
                    'author_name' => $authorName !== '' ? $authorName : null,
                    'username' => data_get($callbackQuery, 'from.username'),
                    'text' => $action === 'yes' ? 'Да' : 'Нет',
                    'responded_at' => $respondedAt,
                    'response_latency_seconds' => $respondedAt->lt($sentAt) ? null : (int) $sentAt->diffInSeconds($respondedAt),
                    'classification' => $action === 'yes' ? 'confirmed' : 'problem',
                    'classification_reason' => 'district_button',
                    'district_key' => $districtKey,
                    'response_kind' => 'button',
                ],
            );
        });

        return [
            'status' => $response->wasRecentlyCreated ? 'saved' : 'already_answered',
            'delivery' => $delivery,
            'response' => $response,
            'action' => $action,
        ];
    }

    private function districtFromText(string $text): ?string
    {
        $matches = [];
        foreach (ScheduledControlTypes::DISTRICTS as $key => $label) {
            $aliases = $key === 'como' ? [$label, 'Комо'] : [$label];
            foreach ($aliases as $alias) {
                if (preg_match('/(?<!\p{L})'.preg_quote($alias, '/').'(?!\p{L})/iu', $text) === 1) {
                    $matches[$key] = true;
                }
            }
        }

        return count($matches) === 1 ? array_key_first($matches) : null;
    }

    public function allDistrictsConfirmed(TelegramScheduledMessageDelivery $delivery): bool
    {
        $latest = $delivery->responses()->whereIn('response_kind', ['button', 'text_answer'])->whereNotNull('district_key')->orderBy('id')->get()
            ->groupBy(fn (TelegramScheduledMessageResponse $response): string => $response->district_key.'|'.$response->telegram_user_id)
            ->map(fn ($answers) => $answers->last())
            ->groupBy('district_key');

        foreach (array_keys(ScheduledControlTypes::DISTRICTS) as $district) {
            $answers = $latest->get($district, collect());
            if ($answers->isEmpty() || $answers->contains(fn (TelegramScheduledMessageResponse $answer): bool => $answer->classification !== 'confirmed')) {
                return false;
            }
        }

        return true;
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
