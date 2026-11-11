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
        $now = CarbonImmutable::now('UTC')->setTimezone(config('app.timezone', 'Europe/Rome'));
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

            $this->prepareSequentialOccurrence($message, $delivery, $scheduledFor);
            if ($delivery->fresh()?->status === 'skipped') {
                continue;
            }

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

        try {
            app(TelegramScheduledControlFollowupService::class)->queueDue();
        } catch (Throwable $exception) {
            $result['failed']++;
            Log::error('Scheduled control follow-up scheduling failed.', ['exception' => class_basename($exception)]);
        }

        try {
            $this->queueReadyClosingPayments();
        } catch (Throwable $exception) {
            $result['failed']++;
            Log::error('Scheduled closing payment scheduling failed.', ['exception' => class_basename($exception)]);
        }

        return $result;
    }

    public function queueClosingPayments(TelegramScheduledMessageDelivery $cleaningsDelivery): bool
    {
        $timezone = 'Europe/Rome';
        $scheduledFor = CarbonImmutable::parse($cleaningsDelivery->getRawOriginal('scheduled_for'), $timezone);
        $now = CarbonImmutable::now('UTC')->setTimezone($timezone);
        if ($cleaningsDelivery->control_type !== ScheduledControlTypes::CLOSING_CLEANINGS
            || $cleaningsDelivery->status !== 'sent' || ! $scheduledFor->isSameDay($now)
            || $cleaningsDelivery->scheduledMessage === null || ! $cleaningsDelivery->scheduledMessage->enabled
            || ! app(TelegramScheduledControlResponseService::class)->allDistrictsConfirmed($cleaningsDelivery)) {
            return true;
        }
        $cleaningsThreadId = $cleaningsDelivery->message_thread_id === null ? null : (string) $cleaningsDelivery->message_thread_id;

        $matches = TelegramScheduledMessage::query()->enabled()
            ->where('control_type', ScheduledControlTypes::CLOSING_PAYMENTS)
            ->with(['telegramChat', 'telegramTopic'])
            ->orderBy('id')
            ->get()
            ->filter(function (TelegramScheduledMessage $candidate) use ($cleaningsDelivery, $cleaningsThreadId, $scheduledFor): bool {
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

        if ($matches->count() !== 1) {
            if ($matches->count() > 1) {
                Log::warning('Scheduled closing payment has ambiguous active templates.', [
                    'source_delivery_id' => $cleaningsDelivery->getKey(),
                    'candidate_count' => $matches->count(),
                ]);
            }

            return false;
        }

        $message = $matches->first();
        $configuredAt = $scheduledFor->startOfDay()->setTimeFromTimeString((string) $message->send_time);
        $minimumAt = $scheduledFor->startOfDay()->setTime(18, 0);
        $scheduledFor = $configuredAt->greaterThan($minimumAt) ? $configuredAt : $minimumAt;
        if ($now->lt($scheduledFor)) {
            return true;
        }

        $occurrences = TelegramScheduledMessageDelivery::query()
            ->where('scheduled_message_id', $message->getKey())
            ->where('scheduled_for', '>=', $scheduledFor->startOfDay()->format('Y-m-d H:i:s'))
            ->where('scheduled_for', '<', $scheduledFor->startOfDay()->addDay()->format('Y-m-d H:i:s'))
            ->orderBy('id')
            ->get();

        $delivery = $occurrences->first();
        if ($delivery !== null) {
            $claimed = TelegramScheduledMessageDelivery::query()->whereKey($delivery->id)
                ->where('status', 'cancelled')->update([
                    'status' => 'pending',
                    'trigger_delivery_id' => $cleaningsDelivery->id,
                    'failure_reason' => null,
                ]);
            if ($claimed !== 1) {
                return true;
            }
            $delivery->refresh();
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
            'trigger_delivery_id' => $cleaningsDelivery->id,
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
                CarbonImmutable::now('UTC')->setTimezone($timezone)->addHour(),
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

    public function prepareSequentialDelivery(TelegramScheduledMessageDelivery $delivery): bool
    {
        $message = $delivery->scheduledMessage;
        if ($message === null) {
            return true;
        }

        $scheduledFor = CarbonImmutable::parse($delivery->getRawOriginal('scheduled_for'), 'Europe/Rome');
        $stage = ScheduledControlTypes::sequentialStage($delivery->control_type, $scheduledFor->format('H:i:s'));
        if ($stage === null || $stage['stage'] !== 'second') {
            return true;
        }

        // Once a Telegram attempt has begun, keep its original scope during retries: the
        // previous HTTP result may be uncertain and a retry must not turn into a duplicate.
        if ($delivery->status === 'retrying' && ($delivery->sequence_context['stage'] ?? null) === 'second') {
            return true;
        }

        $context = $this->sequentialContext($message, $scheduledFor, $stage);
        $sourceId = $context['source_delivery_id'];
        $delivery->update([
            'trigger_delivery_id' => $sourceId,
            'sequence_context' => $context,
            'criteria_hash' => self::criteriaHash($delivery->control_type, (string) $message->message),
        ]);

        if ($context['criteria_matches'] && $context['eligible_districts'] === []) {
            $delivery->update(['status' => 'skipped', 'failure_reason' => 'sequential_all_districts_confirmed']);

            return false;
        }

        return true;
    }

    public static function criteriaHash(string $controlType, string $message): string
    {
        $normalized = preg_replace('/\s+/u', ' ', trim($message)) ?? trim($message);

        return hash('sha256', $controlType."\0".$normalized);
    }

    private function prepareSequentialOccurrence(
        TelegramScheduledMessage $message,
        TelegramScheduledMessageDelivery $delivery,
        CarbonImmutable $scheduledFor,
    ): void {
        $stage = ScheduledControlTypes::sequentialStage((string) $message->control_type, (string) $message->send_time);
        if ($stage === null) {
            return;
        }

        if ($stage['stage'] === 'first') {
            if (! $this->hasSequentialPeer($message, $stage, $scheduledFor)) {
                return;
            }
            $delivery->update(['sequence_context' => [
                'pair' => $stage['pair'], 'stage' => 'first', 'eligible_districts' => array_keys(ScheduledControlTypes::DISTRICTS),
            ]]);

            return;
        }

        $this->prepareSequentialDelivery($delivery);
    }

    private function hasSequentialPeer(TelegramScheduledMessage $message, array $stage, CarbonImmutable $scheduledFor): bool
    {
        return TelegramScheduledMessage::query()->enabled()
            ->whereIn('control_type', $stage['types'])
            ->where('telegram_chat_record_id', $message->telegram_chat_record_id)
            ->where('telegram_topic_record_id', $message->telegram_topic_record_id)
            ->get()
            ->contains(fn (TelegramScheduledMessage $candidate): bool => ScheduledControlTypes::normalizeTime((string) $candidate->send_time) === $stage['next_time']
                && in_array($scheduledFor->dayOfWeekIso, array_map('intval', $candidate->weekdays ?? []), true));
    }

    /** @return array<string, mixed> */
    private function sequentialContext(TelegramScheduledMessage $message, CarbonImmutable $scheduledFor, array $stage): array
    {
        $sourceAt = $scheduledFor->startOfDay()->setTimeFromTimeString($stage['previous_time']);
        $candidates = TelegramScheduledMessage::query()->enabled()
            ->whereIn('control_type', $stage['types'])
            ->where('telegram_chat_record_id', $message->telegram_chat_record_id)
            ->where('telegram_topic_record_id', $message->telegram_topic_record_id)
            ->get()
            ->filter(fn (TelegramScheduledMessage $candidate): bool => ScheduledControlTypes::normalizeTime((string) $candidate->send_time) === $stage['previous_time']
                && in_array($sourceAt->dayOfWeekIso, array_map('intval', $candidate->weekdays ?? []), true))
            ->values();

        $source = null;
        $sourceStatus = 'missing_template';
        if ($candidates->count() === 1) {
            $source = TelegramScheduledMessageDelivery::query()
                ->where('scheduled_message_id', $candidates->first()->id)
                ->where('scheduled_for', $sourceAt->format('Y-m-d H:i:s'))
                ->first();
            $sourceStatus = $source?->status ?? 'missing_delivery';
        } elseif ($candidates->count() > 1) {
            $sourceStatus = 'ambiguous_template';
            Log::warning('Sequential scheduled control source template is ambiguous.', [
                'scheduled_message_id' => $message->id,
                'candidate_count' => $candidates->count(),
            ]);
        }

        $states = [];
        $criteriaMatches = false;
        if ($source !== null && $source->status === 'sent') {
            $districtStates = app(TelegramScheduledControlResponseService::class)->districtAnswerStates($source);
            foreach (array_keys(ScheduledControlTypes::DISTRICTS) as $district) {
                $classifications = collect($districtStates[$district] ?? [])->pluck('classification')->unique()->values();
                $states[$district] = match (true) {
                    $classifications->isEmpty() => 'no_response',
                    $classifications->count() > 1 => 'conflict',
                    default => $classifications->first(),
                };
            }
            $criteriaMatches = is_string($source->criteria_hash) && $source->criteria_hash !== ''
                && hash_equals($source->criteria_hash, self::criteriaHash((string) $message->control_type, (string) $message->message));
        }

        $eligible = array_keys(ScheduledControlTypes::DISTRICTS);
        if ($criteriaMatches) {
            $eligible = array_values(array_filter($eligible, fn (string $district): bool => ($states[$district] ?? null) !== 'confirmed'));
        }

        return [
            'pair' => $stage['pair'], 'stage' => 'second', 'source_delivery_id' => $source?->id,
            'source_time' => $sourceAt->format('H:i'), 'source_status' => $sourceStatus,
            'criteria_matches' => $criteriaMatches, 'previous_states' => $states,
            'eligible_districts' => $eligible,
        ];
    }

    private function queueReadyClosingPayments(): void
    {
        $now = CarbonImmutable::now('UTC')->setTimezone('Europe/Rome');
        TelegramScheduledMessageDelivery::query()
            ->where('control_type', ScheduledControlTypes::CLOSING_CLEANINGS)->where('status', 'sent')
            ->where('scheduled_for', '>=', $now->startOfDay()->format('Y-m-d H:i:s'))
            ->where('scheduled_for', '<', $now->addDay()->startOfDay()->format('Y-m-d H:i:s'))
            ->each(fn (TelegramScheduledMessageDelivery $delivery) => $this->queueClosingPayments($delivery));
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
