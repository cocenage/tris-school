<?php

namespace App\Services\Telegram;

use App\Models\TelegramScheduledMessage;
use App\Models\TelegramScheduledMessageDelivery;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Throwable;

class TelegramScheduledMessageDeliveryService
{
    /** @return array{due: int, sent: int, failed: int, duplicate: int, telegram_actions: int} */
    public function sendDue(): array
    {
        $now = CarbonImmutable::now(config('app.timezone', 'Europe/Rome'));
        $timezone = config('app.timezone', 'Europe/Rome');
        $graceMinutes = max(0, (int) config('services.telegram.scheduled_delivery_grace_minutes', 5));
        $result = ['due' => 0, 'sent' => 0, 'failed' => 0, 'duplicate' => 0, 'telegram_actions' => 0];

        $messages = TelegramScheduledMessage::query()
            ->enabled()
            ->orderBy('id')
            ->get()
            ->filter(fn (TelegramScheduledMessage $message): bool => $this->isWithinDeliveryWindow($message, $now, $timezone, $graceMinutes));

        $result['due'] = $messages->count();

        foreach ($messages as $message) {
            $scheduledFor = $this->scheduledFor($now, (string) $message->send_time, $timezone);

            try {
                $delivery = $this->reserveOccurrence($message, $scheduledFor);
            } catch (Throwable $exception) {
                $result['failed']++;
                Log::warning('Telegram scheduled message could not reserve its delivery occurrence.', [
                    'scheduled_message_id' => $message->getKey(),
                    'exception' => class_basename($exception),
                ]);

                continue;
            }

            if ($delivery === null) {
                $result['duplicate']++;

                continue;
            }

            try {
                $chat = $message->telegramChat;
                $topic = $message->telegramTopic;
                $chatId = $chat?->telegram_chat_id;
                $threadId = $topic?->telegram_thread_id;

                $delivery->update([
                    'chat_id' => $chatId,
                    'message_thread_id' => $threadId,
                ]);

                $destinationIsValid = $chat !== null
                    && $chat->is_enabled
                    && ($message->telegram_topic_record_id === null
                        || ($topic !== null
                            && $topic->is_enabled
                            && (string) $topic->telegram_chat_id === (string) $message->telegram_chat_record_id));

                if (! $destinationIsValid || ! filled($chatId)) {
                    $this->fail($delivery, 'destination_unavailable');
                    $result['failed']++;

                    continue;
                }

                $result['telegram_actions']++;
                $messageId = app(TelegramBotService::class)->sendScheduledMessage(
                    (string) $chatId,
                    htmlspecialchars((string) $message->message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                    $threadId === null ? null : (string) $threadId,
                );

                if ($messageId === null) {
                    $this->fail($delivery, 'telegram_send_failed');
                    $result['failed']++;

                    continue;
                }

                $delivery->update([
                    'status' => 'sent',
                    'sent_at' => CarbonImmutable::now($timezone),
                    'telegram_message_id' => $messageId,
                    'failure_reason' => null,
                ]);
                $result['sent']++;
            } catch (Throwable $exception) {
                $this->fail($delivery, 'exception:'.class_basename($exception));
                $result['failed']++;

                Log::warning('Telegram scheduled message delivery failed.', [
                    'scheduled_message_id' => $message->getKey(),
                    'delivery_id' => $delivery->getKey(),
                    'exception' => class_basename($exception),
                ]);
            }
        }

        return $result;
    }

    private function reserveOccurrence(
        TelegramScheduledMessage $message,
        CarbonImmutable $scheduledFor,
    ): ?TelegramScheduledMessageDelivery {
        try {
            return TelegramScheduledMessageDelivery::query()->create([
                'scheduled_message_id' => $message->getKey(),
                'control_type' => $message->control_type,
                'scheduled_for' => $scheduledFor,
                'status' => 'pending',
            ]);
        } catch (QueryException $exception) {
            $existing = TelegramScheduledMessageDelivery::query()
                ->where('scheduled_message_id', $message->getKey())
                ->where('scheduled_for', $scheduledFor->format('Y-m-d H:i:s'))
                ->first();

            if ($existing !== null) {
                return null;
            }

            throw $exception;
        }
    }

    private function scheduledFor(CarbonImmutable $now, string $time, string $timezone): CarbonImmutable
    {
        [$hour, $minute, $second] = array_pad(array_map('intval', explode(':', $time)), 3, 0);

        return $now->setTimezone($timezone)->startOfDay()->setTime($hour, $minute, $second);
    }

    private function isWithinDeliveryWindow(
        TelegramScheduledMessage $message,
        CarbonImmutable $now,
        string $timezone,
        int $graceMinutes,
    ): bool {
        $scheduledFor = $this->scheduledFor($now, (string) $message->send_time, $timezone);

        return in_array($scheduledFor->dayOfWeekIso, array_map('intval', $message->weekdays ?? []), true)
            && $scheduledFor->lessThanOrEqualTo($now)
            && $scheduledFor->diffInSeconds($now) <= $graceMinutes * 60;
    }

    private function fail(TelegramScheduledMessageDelivery $delivery, string $reason): void
    {
        $delivery->update([
            'status' => 'failed',
            'failure_reason' => $reason,
        ]);
    }
}
