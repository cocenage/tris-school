<?php

namespace App\Services\Telegram;

use App\Jobs\DeliverScheduledTelegramMessage;
use App\Models\TelegramScheduledMessage;
use App\Models\TelegramScheduledMessageDelivery;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Throwable;

class TelegramScheduledMessageDeliveryService
{
    /** @return array{due: int, queued: int, failed: int, duplicate: int} */
    public function sendDue(): array
    {
        $now = CarbonImmutable::now(config('app.timezone', 'Europe/Rome'));
        $timezone = config('app.timezone', 'Europe/Rome');
        $graceMinutes = max(0, (int) config('services.telegram.scheduled_delivery_grace_minutes', 5));
        $result = ['due' => 0, 'queued' => 0, 'failed' => 0, 'duplicate' => 0];

        $messages = TelegramScheduledMessage::query()
            ->enabled()
            ->orderBy('id')
            ->get()
            ->filter(fn (TelegramScheduledMessage $message): bool => $this->isWithinDeliveryWindow($message, $now, $timezone, $graceMinutes));

        $result['due'] = $messages->count();

        foreach ($messages as $message) {
            $scheduledTime = ScheduledControlTypes::scheduledTime((string) $message->control_type, (string) $message->send_time);
            if ($scheduledTime === null) {
                continue;
            }
            $scheduledFor = $this->scheduledFor($now, $scheduledTime, $timezone);

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

            if (config('queue.connections.'.config('queue.default').'.driver') === 'sync') {
                $this->fail($delivery, 'queue_connection_sync');
                $result['failed']++;

                Log::error('Scheduled Telegram delivery requires a non-sync queue connection.', [
                    'delivery_id' => $delivery->getKey(),
                ]);

                continue;
            }

            try {
                Bus::dispatch(new DeliverScheduledTelegramMessage(
                    (int) $delivery->getKey(),
                    $scheduledFor->addMinutes(60),
                ));
                $result['queued']++;
            } catch (Throwable $exception) {
                $this->fail($delivery, 'queue_dispatch_failed:'.class_basename($exception));
                $result['failed']++;

                Log::error('Scheduled Telegram delivery could not be queued.', [
                    'delivery_id' => $delivery->getKey(),
                    'exception' => class_basename($exception),
                ]);
            }
        }

        return $result;
    }

    public function queueClosingPayments(TelegramScheduledMessageDelivery $cleaningsDelivery): bool
    {
        $timezone = config('app.timezone', 'Europe/Rome');
        $scheduledFor = CarbonImmutable::parse($cleaningsDelivery->getRawOriginal('scheduled_for'), $timezone);
        $cleaningsThreadId = $cleaningsDelivery->message_thread_id === null ? null : (string) $cleaningsDelivery->message_thread_id;

        $message = TelegramScheduledMessage::query()->enabled()
            ->where('control_type', ScheduledControlTypes::CLOSING_PAYMENTS)
            ->with(['telegramChat', 'telegramTopic'])
            ->orderBy('id')
            ->get()
            ->first(function (TelegramScheduledMessage $candidate) use ($cleaningsDelivery, $cleaningsThreadId, $scheduledFor): bool {
                $candidateThreadId = $candidate->telegramTopic?->telegram_thread_id;
                $candidateThreadId = $candidateThreadId === null ? null : (string) $candidateThreadId;

                return (string) $candidate->telegramChat?->telegram_chat_id === (string) $cleaningsDelivery->chat_id
                    && $candidateThreadId === $cleaningsThreadId
                    && $candidate->telegramChat?->is_enabled === true
                    && ($candidate->telegram_topic_record_id === null
                        || ($candidate->telegramTopic !== null
                            && $candidate->telegramTopic->is_enabled
                            && (string) $candidate->telegramTopic->telegram_chat_id === (string) $candidate->telegram_chat_record_id))
                    && in_array($scheduledFor->dayOfWeekIso, array_map('intval', $candidate->weekdays ?? []), true);
            });

        if ($message === null) {
            return false;
        }

        $occurrences = TelegramScheduledMessageDelivery::query()
            ->where('scheduled_message_id', $message->getKey())
            ->where('scheduled_for', '>=', $scheduledFor->startOfDay()->format('Y-m-d H:i:s'))
            ->where('scheduled_for', '<', $scheduledFor->startOfDay()->addDay()->format('Y-m-d H:i:s'))
            ->orderBy('id')
            ->get();

        $alreadyHandled = $occurrences->first(fn (TelegramScheduledMessageDelivery $row): bool => in_array($row->status, ['sent', 'pending', 'retrying'], true));
        if ($alreadyHandled !== null) {
            return true;
        }

        $delivery = $occurrences->first();
        if ($delivery !== null) {
            $delivery->update([
                'scheduled_for' => $scheduledFor,
                'status' => 'pending',
                'bot_type' => $cleaningsDelivery->bot_type,
                'failure_reason' => null,
            ]);
        } else {
            try {
                $delivery = $this->reserveOccurrence($message, $scheduledFor);
            } catch (Throwable $exception) {
                Log::warning('Scheduled closing payment occurrence could not be reserved.', [
                    'source_delivery_id' => $cleaningsDelivery->getKey(),
                    'exception' => class_basename($exception),
                ]);

                return false;
            }

            if ($delivery === null) {
                return true;
            }
        }

        $chat = $message->telegramChat;
        $topic = $message->telegramTopic;
        $delivery->update([
            'chat_id' => $cleaningsDelivery->chat_id,
            'message_thread_id' => $cleaningsDelivery->message_thread_id,
        ]);

        if (! $chat || ! $chat->is_enabled || ($message->telegram_topic_record_id !== null && (! $topic || ! $topic->is_enabled))) {
            $this->fail($delivery, 'destination_unavailable');

            return false;
        }

        if (config('queue.connections.'.config('queue.default').'.driver') === 'sync') {
            $this->fail($delivery, 'queue_connection_sync');

            return false;
        }

        try {
            Bus::dispatch(new DeliverScheduledTelegramMessage(
                (int) $delivery->getKey(),
                CarbonImmutable::now($timezone)->addHour(),
            ));
        } catch (Throwable $exception) {
            $this->fail($delivery, 'queue_dispatch_failed:'.class_basename($exception));
            Log::warning('Scheduled closing payment could not be queued.', [
                'delivery_id' => $delivery->getKey(),
                'exception' => class_basename($exception),
            ]);

            return false;
        }

        return true;
    }

    private function reserveOccurrence(
        TelegramScheduledMessage $message,
        CarbonImmutable $scheduledFor,
    ): ?TelegramScheduledMessageDelivery {
        try {
            return TelegramScheduledMessageDelivery::query()->create([
                'scheduled_message_id' => $message->getKey(),
                'control_type' => $message->control_type,
                'bot_type' => array_key_exists((string) $message->control_type, ScheduledControlTypes::LABELS) ? 'work' : 'scheduled',
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
        $sendTime = ScheduledControlTypes::scheduledTime((string) $message->control_type, (string) $message->send_time);
        if ($sendTime === null) {
            return false;
        }
        $scheduledFor = $this->scheduledFor($now, $sendTime, $timezone);

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
