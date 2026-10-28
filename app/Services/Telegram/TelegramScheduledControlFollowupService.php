<?php

namespace App\Services\Telegram;

use App\Jobs\DeliverScheduledControlFollowup;
use App\Models\TelegramScheduledControlFollowup;
use App\Models\TelegramScheduledMessage;
use App\Models\TelegramScheduledMessageDelivery;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Throwable;

class TelegramScheduledControlFollowupService
{
    /** @var array<string, list<string>> */
    private const NEXT_STAGES = [
        'first_cleanings_started' => ['first_cleanings_finishing', 'cleanings_completed'],
        'first_cleanings_finishing' => ['cleanings_completed'],
        'second_cleanings_finishing' => ['cleanings_completed'],
    ];

    /** @var array<string, bool> */
    private array $nextStageCache = [];

    public function queueDue(): void
    {
        $now = CarbonImmutable::now('UTC')->setTimezone('Europe/Rome');
        $deliveries = TelegramScheduledMessageDelivery::query()
            ->where('bot_type', 'work')->where('status', 'sent')
            ->whereIn('control_type', array_keys(ScheduledControlTypes::LABELS))
            ->where('scheduled_for', '>=', $now->startOfDay()->format('Y-m-d H:i:s'))
            ->where('scheduled_for', '<', $now->addDay()->startOfDay()->format('Y-m-d H:i:s'))
            ->with(['scheduledMessage.telegramChat', 'scheduledMessage.telegramTopic', 'responses', 'followups'])
            ->get();

        foreach ($deliveries as $delivery) {
            $states = app(TelegramScheduledControlResponseService::class)->districtAnswerStates($delivery);
            foreach (array_keys(ScheduledControlTypes::DISTRICTS) as $district) {
                $candidate = $this->candidate($delivery, $district, $now, $states);
                if ($candidate === null || $candidate['due_at']->gt($now)) {
                    continue;
                }

                $this->reserveAndDispatch($delivery, $district, $candidate, $now);
            }
        }
    }

    /** @param array{kind: string, sequence: int, due_at: CarbonImmutable} $candidate */
    private function reserveAndDispatch(TelegramScheduledMessageDelivery $delivery, string $district, array $candidate, CarbonImmutable $now): void
    {
        $requestedAt = $now->setTimezone('UTC')->format('Y-m-d H:i:s');
        $followup = $delivery->followups->first(fn (TelegramScheduledControlFollowup $row): bool => $row->district_key === $district
            && $row->kind === $candidate['kind'] && (int) $row->sequence === $candidate['sequence']);
        $created = false;
        if ($followup === null) {
            try {
                $followup = TelegramScheduledControlFollowup::query()->create([
                    'delivery_id' => $delivery->id,
                    'district_key' => $district,
                    'kind' => $candidate['kind'],
                    'sequence' => $candidate['sequence'],
                    'due_at' => $candidate['due_at']->setTimezone('Europe/Rome'),
                    'due_at_utc' => $candidate['due_at']->setTimezone('UTC')->format('Y-m-d H:i:s'),
                    'dispatch_requested_at_utc' => $requestedAt,
                    'status' => 'queued',
                ]);
                $created = true;
            } catch (QueryException $exception) {
                $followup = TelegramScheduledControlFollowup::query()
                    ->where('delivery_id', $delivery->id)->where('district_key', $district)
                    ->where('kind', $candidate['kind'])->where('sequence', $candidate['sequence'])->first();
                if ($followup === null) {
                    throw $exception;
                }
            }
        }

        if (! $created) {
            $query = TelegramScheduledControlFollowup::query()->whereKey($followup->id);
            if ($followup->status === 'cancelled') {
                $query->where('status', 'cancelled');
            } elseif ($followup->status === 'queued') {
                $previous = $followup->getRawOriginal('dispatch_requested_at_utc');
                if ($previous !== null && CarbonImmutable::parse($previous, 'UTC')->gt($now->setTimezone('UTC')->subMinutes(5))) {
                    return;
                }
                $query->where('status', 'queued');
                $previous === null ? $query->whereNull('dispatch_requested_at_utc') : $query->where('dispatch_requested_at_utc', $previous);
            } else {
                return;
            }

            $claimed = $query->update([
                'status' => 'queued', 'failure_reason' => null,
                'due_at' => $candidate['due_at']->setTimezone('Europe/Rome'),
                'due_at_utc' => $candidate['due_at']->setTimezone('UTC')->format('Y-m-d H:i:s'),
                'dispatch_requested_at_utc' => $requestedAt,
            ]);
            if ($claimed !== 1) {
                return;
            }
        }

        if (config('queue.connections.'.config('queue.default').'.driver') === 'sync') {
            $followup->update(['status' => 'failed', 'failure_reason' => 'queue_connection_sync']);

            return;
        }

        try {
            Bus::dispatch(new DeliverScheduledControlFollowup($followup->id));
        } catch (Throwable $exception) {
            TelegramScheduledControlFollowup::query()->whereKey($followup->id)->where('status', 'queued')
                ->update(['failure_reason' => 'queue_dispatch_failed']);
            Log::warning('Scheduled control follow-up could not be queued.', [
                'followup_id' => $followup->id, 'exception' => class_basename($exception),
            ]);
        }
    }

    public function isStillDue(TelegramScheduledControlFollowup $followup, CarbonImmutable $now): bool
    {
        $delivery = $followup->delivery()->with(['scheduledMessage.telegramChat', 'scheduledMessage.telegramTopic', 'responses', 'followups'])->first();
        if ($delivery === null) {
            return false;
        }
        $candidate = $this->candidate($delivery, $followup->district_key, $now, null, $followup->id);

        return $candidate !== null && $candidate['kind'] === $followup->kind
            && $candidate['sequence'] === (int) $followup->sequence && $candidate['due_at']->lte($now);
    }

    /** @return array{kind: string, sequence: int, due_at: CarbonImmutable}|null */
    private function candidate(TelegramScheduledMessageDelivery $delivery, string $district, CarbonImmutable $now, ?array $states = null, ?int $checkingFollowupId = null): ?array
    {
        $message = $delivery->scheduledMessage;
        if ($message === null || ! $message->enabled || $delivery->status !== 'sent' || ! $delivery->sent_at
            || ! isset(ScheduledControlTypes::DISTRICTS[$district]) || ! $this->destinationUnchanged($delivery, $message)) {
            return null;
        }

        if ($delivery->getRawOriginal('sent_at_utc') === null
            && $this->hasAmbiguousLocalTimestamp($delivery->getRawOriginal('sent_at'))) {
            return null;
        }
        $sentAt = $delivery->getRawOriginal('sent_at_utc') !== null
            ? CarbonImmutable::parse($delivery->getRawOriginal('sent_at_utc'), 'UTC')->setTimezone('Europe/Rome')
            : CarbonImmutable::parse($delivery->getRawOriginal('sent_at'), 'Europe/Rome');
        $scheduledAt = CarbonImmutable::parse($delivery->getRawOriginal('scheduled_for'), 'Europe/Rome');
        if (! $scheduledAt->isSameDay($now) || ! $sentAt->isSameDay($now) || $this->hasReachedNextStage($delivery, $message, $now)) {
            return null;
        }
        if ($message->followup_cutoff && $now->gt($now->startOfDay()->setTimeFromTimeString($message->followup_cutoff))) {
            return null;
        }

        $states ??= app(TelegramScheduledControlResponseService::class)->districtAnswerStates($delivery);
        $latest = collect($states[$district] ?? []);
        $followups = $delivery->followups->where('district_key', $district);
        if ($latest->isEmpty() || ($latest->contains(fn ($answer): bool => $answer['classification'] === 'unclear')
            && ! $latest->contains(fn ($answer): bool => in_array($answer['classification'], ['problem', 'partial'], true)))) {
            $reminder = $followups->firstWhere('kind', 'reminder');
            if ($reminder !== null && ! in_array($reminder->status, ['queued', 'cancelled'], true) && ! ($reminder->status === 'sending' && $reminder->id === $checkingFollowupId)) {
                return null;
            }

            return $message->followup_reminder_enabled ? [
                'kind' => 'reminder', 'sequence' => 1,
                'due_at' => $sentAt->addMinutes(max(1, (int) $message->followup_reminder_minutes)),
            ] : null;
        }

        $problems = $latest->filter(fn ($answer): bool => in_array($answer['classification'], ['problem', 'partial'], true));
        if ($problems->isEmpty() || ! $message->followup_repeat_enabled) {
            return null;
        }

        $repeats = $followups->where('kind', 'repeat');
        $sent = $repeats->where('status', 'sent')->sortBy('sequence')->values();
        $sequence = $sent->count() + 1;
        if ($sequence > min(2, max(0, (int) $message->followup_max_repeats))) {
            return null;
        }
        $existing = $repeats->firstWhere('sequence', $sequence);
        if ($existing !== null && ! in_array($existing->status, ['queued', 'cancelled'], true) && ! ($existing->status === 'sending' && $existing->id === $checkingFollowupId)) {
            return null;
        }

        $lastSent = $sent->last();
        if ($lastSent !== null) {
            $lastSentAt = $lastSent->getRawOriginal('sent_at_utc') !== null
                ? CarbonImmutable::parse($lastSent->getRawOriginal('sent_at_utc'), 'UTC')->setTimezone('Europe/Rome')
                : CarbonImmutable::parse($lastSent->getRawOriginal('sent_at'), 'Europe/Rome');
            $problems = $problems->filter(fn ($answer): bool => (int) $answer['response']->followup_id === (int) $lastSent->id || CarbonImmutable::parse($answer['responded_at'], 'Europe/Rome')->gt($lastSentAt));
            if ($problems->isEmpty()) {
                return null;
            }
        }
        $problem = $problems->sortBy(fn ($answer) => CarbonImmutable::parse($answer['responded_at'])->timestamp)->first();
        if ($problem['response']->getRawOriginal('responded_at_utc') === null
            && $this->hasAmbiguousLocalTimestamp($problem['response']->getRawOriginal('responded_at'))) {
            return null;
        }

        return [
            'kind' => 'repeat', 'sequence' => $sequence,
            'due_at' => CarbonImmutable::parse($problem['responded_at'], 'Europe/Rome')
                ->addMinutes(max(1, (int) $message->followup_interval_minutes)),
        ];
    }

    public function hasAmbiguousLocalTimestamp(?string $value): bool
    {
        if ($value === null) {
            return true;
        }

        try {
            $wall = CarbonImmutable::createFromFormat('!Y-m-d H:i:s', $value, 'UTC');
            $transitions = (new \DateTimeZone('Europe/Rome'))->getTransitions($wall->timestamp - 86400, $wall->timestamp + 86400);
            for ($index = 1; $index < count($transitions); $index++) {
                $previous = $transitions[$index - 1]['offset'];
                $current = $transitions[$index]['offset'];
                $transition = $transitions[$index]['ts'];
                if ($current < $previous && $wall->timestamp >= $transition + $current && $wall->timestamp < $transition + $previous) {
                    return true;
                }
            }

            return false;
        } catch (Throwable) {
            return true;
        }
    }

    private function destinationUnchanged(TelegramScheduledMessageDelivery $delivery, TelegramScheduledMessage $message): bool
    {
        $chat = $message->telegramChat;
        $topic = $message->telegramTopic;

        return $chat !== null && $chat->is_enabled
            && (string) $chat->telegram_chat_id === (string) $delivery->chat_id
            && ($message->telegram_topic_record_id === null
                ? $delivery->message_thread_id === null
                : $topic !== null && $topic->is_enabled
                    && (string) $topic->telegram_chat_id === (string) $message->telegram_chat_record_id
                    && (string) $topic->telegram_thread_id === (string) $delivery->message_thread_id);
    }

    private function hasReachedNextStage(TelegramScheduledMessageDelivery $delivery, TelegramScheduledMessage $message, CarbonImmutable $now): bool
    {
        $types = self::NEXT_STAGES[$delivery->control_type] ?? [];
        if ($types === []) {
            return false;
        }
        $cacheKey = $delivery->id.'|'.$now->setTimezone('UTC')->format('Y-m-d H:i');
        if (array_key_exists($cacheKey, $this->nextStageCache)) {
            return $this->nextStageCache[$cacheKey];
        }
        $scheduledAt = CarbonImmutable::parse($delivery->getRawOriginal('scheduled_for'), 'Europe/Rome');

        return $this->nextStageCache[$cacheKey] = TelegramScheduledMessage::query()->enabled()->whereIn('control_type', $types)
            ->where('telegram_chat_record_id', $message->telegram_chat_record_id)
            ->where('telegram_topic_record_id', $message->telegram_topic_record_id)->get()
            ->contains(function (TelegramScheduledMessage $next) use ($now, $scheduledAt): bool {
                $time = ScheduledControlTypes::scheduledTime((string) $next->control_type, (string) $next->send_time);
                if ($time === null || ! in_array($now->dayOfWeekIso, array_map('intval', $next->weekdays ?? []), true)) {
                    return false;
                }
                $nextAt = $now->startOfDay()->setTimeFromTimeString($time);

                return $nextAt->gt($scheduledAt) && $nextAt->lte($now);
            });
    }
}
