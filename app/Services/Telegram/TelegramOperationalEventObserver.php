<?php

namespace App\Services\Telegram;

use App\Models\TelegramMessage;
use App\Models\TelegramOperationalEvent;
use App\Models\TelegramOperationalEventEvidence;
use App\Models\TelegramOperationalObservation;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

class TelegramOperationalEventObserver
{
    public function __construct(
        protected TelegramOperationalInterpreter $interpreter,
    ) {}

    public function observe(
        TelegramMessage $message,
        string $evaluationKind = 'message',
        Carbon|string|null $asOf = null,
    ): array {
        if (! in_array($evaluationKind, ['message', 'unanswered'], true)) {
            throw new \InvalidArgumentException('Unsupported operational evaluation kind.');
        }

        $message->loadMissing(['chat', 'topic', 'telegramUser', 'attachments']);
        $clock = $asOf instanceof Carbon
            ? $asOf->copy()
            : Carbon::parse($asOf ?: now(config('app.timezone', 'Europe/Rome')), config('app.timezone', 'Europe/Rome'));
        $raw = $this->normalizedRaw($message->raw);
        $revisionHash = $this->revisionHash($message, $raw);

        $existing = TelegramOperationalObservation::query()
            ->with('evidence.event')
            ->where('telegram_message_id', $message->id)
            ->where('source_revision_hash', $revisionHash)
            ->where('evaluation_kind', $evaluationKind)
            ->first();

        if ($existing?->state === 'completed') {
            return $this->resultFromObservation($existing, true);
        }

        try {
            return DB::connection('analytics')->transaction(function () use (
                $message,
                $evaluationKind,
                $clock,
                $raw,
                $revisionHash,
            ): array {
                $observation = TelegramOperationalObservation::query()
                    ->where('telegram_message_id', $message->id)
                    ->where('source_revision_hash', $revisionHash)
                    ->where('evaluation_kind', $evaluationKind)
                    ->lockForUpdate()
                    ->first();

                if ($observation?->state === 'completed') {
                    return $this->resultFromObservation($observation->load('evidence.event'), true);
                }

                $observation ??= new TelegramOperationalObservation([
                    'telegram_message_id' => $message->id,
                    'source_revision_hash' => $revisionHash,
                    'evaluation_kind' => $evaluationKind,
                ]);
                $observation->fill([
                    'state' => 'processing',
                    'outcome' => null,
                    'reason_code' => null,
                    'confidence' => null,
                    'uncertainty' => null,
                    'processed_at' => null,
                    'error_code' => null,
                    'is_current_revision' => true,
                ])->save();

                if ($evaluationKind === 'unanswered') {
                    return $this->evaluateUnanswered($message, $observation, $clock, $revisionHash);
                }

                $eligibilityReason = $this->eligibilityReason($message, $raw);

                if ($eligibilityReason !== null) {
                    return $this->completeNoEventWithCorrection($message, $observation, $eligibilityReason, $clock);
                }

                $text = trim((string) ($message->text ?: $message->caption ?: ''));
                $decision = $this->interpreter->interpret($text);

                if (! $decision['meaningful']) {
                    $decision = $this->delayContinuationDecision($message, $text) ?? $decision;
                }

                if (! $decision['meaningful']) {
                    return $this->completeNoEventWithCorrection($message, $observation, $decision['reason_code'], $clock);
                }

                $previousEvent = $this->previousRevisionEvents($message, $observation)->first();
                $candidates = $previousEvent
                    ? collect([$previousEvent])
                    : ($decision['primary_type'] === 'positive_contribution'
                        ? collect()
                        : $this->correlationCandidates($message, $decision, $raw));

                if (! $previousEvent && $candidates->count() > 1 && $this->requiresExistingSituation($decision)) {
                    return $this->completeAmbiguous($observation, $clock);
                }

                if (! $previousEvent && $candidates->isEmpty() && $decision['transition'] === 'resolved') {
                    return $this->completeNoEventWithCorrection($message, $observation, 'uncorrelated_resolution', $clock);
                }

                $this->markPreviousRevisionSuperseded($message, $observation);
                $event = $candidates->count() === 1
                    ? $this->appendToEvent($candidates->first(), $message, $observation, $decision)
                    : $this->createInitialEvent($message, $observation, $decision);
                $transition = $observation->evidence()->latest('id')->value('transition');
                $outcome = $decision['is_question']
                    ? 'pending_question'
                    : ($transition ?: 'created');
                $dueAt = $decision['is_question']
                    ? $this->messageTime($message)->addHours(4)
                    : null;

                $observation->fill([
                    'state' => 'completed',
                    'outcome' => $outcome,
                    'reason_code' => $decision['reason_code'],
                    'confidence' => $decision['confidence'],
                    'uncertainty' => $decision['uncertainty'],
                    'unanswered_due_at' => $dueAt,
                    'processed_at' => $clock,
                ])->save();

                return $this->result($message, $observation, $event, false);
            }, 3);
        } catch (Throwable $exception) {
            TelegramOperationalObservation::query()->updateOrCreate(
                [
                    'telegram_message_id' => $message->id,
                    'source_revision_hash' => $revisionHash,
                    'evaluation_kind' => $evaluationKind,
                ],
                [
                    'state' => 'failed',
                    'outcome' => null,
                    'processed_at' => $clock,
                    'error_code' => class_basename($exception),
                    'is_current_revision' => true,
                ],
            );

            throw $exception;
        }
    }

    private function createInitialEvent(
        TelegramMessage $message,
        TelegramOperationalObservation $observation,
        array $decision,
    ): TelegramOperationalEvent {
        $occurredAt = $this->messageTime($message);
        $status = $decision['transition'] === 'resolved' ? 'resolved' : 'open';
        $event = TelegramOperationalEvent::query()->create([
            'event_key' => 'telegram:'.$message->chat->telegram_chat_id.':'.$message->message_id,
            'root_message_id' => $message->id,
            'telegram_chat_id' => $message->telegram_chat_id,
            'telegram_topic_id' => $message->telegram_topic_id,
            'apartment_id' => $message->topic?->apartment_id,
            'primary_type' => $decision['primary_type'],
            'types' => $decision['types'],
            'summary' => $decision['summary'],
            'status' => $status,
            'confidence' => $decision['confidence'],
            'uncertainty' => $decision['uncertainty'],
            'subject_key' => $decision['subject_key'],
            'first_observed_at' => $occurredAt,
            'last_observed_at' => $occurredAt,
            'resolved_at' => $status === 'resolved' ? $occurredAt : null,
        ]);

        TelegramOperationalEventEvidence::query()->create([
            'operational_event_id' => $event->id,
            'observation_id' => $observation->id,
            'role' => $decision['role'],
            'transition' => 'created',
            'status_before' => null,
            'status_after' => $status,
            'confidence' => $decision['confidence'],
            'uncertainty' => $decision['uncertainty'],
            'occurred_at' => $occurredAt,
            'is_current_revision' => true,
        ]);

        return $this->reprojectEvent($event);
    }

    private function appendToEvent(
        TelegramOperationalEvent $event,
        TelegramMessage $message,
        TelegramOperationalObservation $observation,
        array $decision,
    ): TelegramOperationalEvent {
        $event = TelegramOperationalEvent::query()->lockForUpdate()->findOrFail($event->id);
        $occurredAt = $this->messageTime($message);
        $before = $event->status;

        if ($decision['transition'] === 'resolved') {
            $transition = 'resolved';
            $after = 'resolved';
        } elseif ($before === 'resolved' && $decision['role'] === 'report'
            && $decision['primary_type'] === $event->primary_type
            && ! $decision['is_question']
            && $decision['confidence'] !== 'low') {
            $transition = 'reopened';
            $after = 'reopened';
        } elseif (in_array($decision['role'], ['action', 'commitment', 'request', 'question'], true)
            || ($event->primary_type === 'delay' && $this->delayMinutes($decision['summary']) !== null)) {
            $transition = 'updated';
            $after = $before;
        } else {
            $transition = 'evidence';
            $after = $before;
        }

        $event->forceFill([
            'types' => collect($event->types)->push($decision['primary_type'])->unique()->values()->all(),
            'summary' => $event->primary_type === 'delay' && $this->delayMinutes((string) $decision['summary']) !== null
                ? 'Задержка примерно на '.$this->delayMinutes((string) $decision['summary']).' минут.'
                : $event->summary,
            'status' => $after,
            'confidence' => $event->confidence === 'low' || $decision['confidence'] === 'low'
                ? 'low'
                : $decision['confidence'],
            'uncertainty' => $decision['uncertainty'] ?: $event->uncertainty,
            'last_observed_at' => $occurredAt,
            'resolved_at' => $after === 'resolved'
                ? ($transition === 'resolved' ? $occurredAt : $event->resolved_at)
                : null,
        ])->save();

        TelegramOperationalEventEvidence::query()->create([
            'operational_event_id' => $event->id,
            'observation_id' => $observation->id,
            'role' => $transition === 'reopened' ? 'recurrence' : $decision['role'],
            'transition' => $transition,
            'status_before' => $before,
            'status_after' => $after,
            'confidence' => $decision['confidence'],
            'uncertainty' => $decision['uncertainty'],
            'occurred_at' => $occurredAt,
            'is_current_revision' => true,
        ]);

        return $event;
    }

    private function correlationCandidates(TelegramMessage $message, array $decision, array $raw): Collection
    {
        $replyTo = data_get($this->messagePayload($raw), 'reply_to_message.message_id');

        if ($replyTo !== null) {
            $replyEvents = TelegramOperationalEvent::query()
                ->where('telegram_chat_id', $message->telegram_chat_id)
                ->where('telegram_topic_id', $message->telegram_topic_id)
                ->whereHas('evidence', fn ($query) => $query
                    ->where('is_current_revision', true)
                    ->whereHas('observation.message', fn ($messageQuery) => $messageQuery
                        ->where('telegram_chat_id', $message->telegram_chat_id)
                        ->where('message_id', (string) $replyTo)))
                ->get();

            if ($replyEvents->isNotEmpty()) {
                return $replyEvents;
            }
        }

        // Separate questions need an explicit reply link; a shared topic is not an answer thread.
        if ($decision['role'] === 'question') {
            return collect();
        }

        $delayCandidate = $this->delayCandidate($message, $decision);

        if ($delayCandidate !== null) {
            return collect([$delayCandidate]);
        }

        $subjectParts = array_values(array_filter(explode('|', (string) $decision['subject_key'])));

        if ($subjectParts === []) {
            return collect();
        }

        $query = TelegramOperationalEvent::query()
            ->where('telegram_chat_id', $message->telegram_chat_id)
            ->where('status', '!=', 'dismissed')
            ->whereBetween('last_observed_at', [
                $this->messageTime($message)->subDays(7),
                $this->messageTime($message),
            ])
            ->where(function ($query) use ($message) {
                $message->telegram_topic_id === null
                    ? $query->whereNull('telegram_topic_id')
                    : $query->where('telegram_topic_id', $message->telegram_topic_id);
            });

        if ($decision['role'] === 'report' && $decision['transition'] === 'created') {
            $query->where('primary_type', $decision['primary_type']);
        }

        return $query->lockForUpdate()->get()->filter(function (TelegramOperationalEvent $event) use ($subjectParts, $decision, $message): bool {
            $candidateParts = array_values(array_filter(explode('|', (string) $event->subject_key)));
            $shared = array_values(array_intersect($subjectParts, $candidateParts));

            if ($shared === []) {
                return false;
            }

            $specificSubjects = ['lock', 'keys', 'payment', 'technical', 'hood_light'];
            $hasSpecificSubject = array_intersect($shared, $specificSubjects) !== [];
            $hasDetailedSubject = count($shared) >= 2
                && collect($subjectParts)->sort()->values()->all() === collect($candidateParts)->sort()->values()->all();

            if (! $hasSpecificSubject && ! $hasDetailedSubject) {
                return false;
            }

            if ($decision['role'] === 'report' && $decision['transition'] === 'created') {
                $confirmedRecurrence = $event->status === 'resolved'
                    && preg_match('/\bснова\b/ui', (string) $decision['summary']) === 1;

                if (! $confirmedRecurrence && ($message->telegram_user_id === null
                    || $event->rootMessage?->telegram_user_id !== $message->telegram_user_id)) {
                    return false;
                }

                if (in_array('hood_light', $shared, true)
                    && $event->last_observed_at->lt($this->messageTime($message)->subMinutes(45))
                    && ! $confirmedRecurrence) {
                    return false;
                }
            }

            return $decision['role'] !== 'report'
                || $decision['transition'] !== 'created'
                || $event->primary_type === $decision['primary_type'];
        })->values();
    }

    private function delayContinuationDecision(TelegramMessage $message, string $text): ?array
    {
        if (preg_match('/^(?:буду\s+)?минут\s+через\s+\d{1,3}[.!]?$/ui', $text) !== 1
            && preg_match('/^минут\s+на\s+\d{1,3}[.!]?$/ui', $text) !== 1) {
            return null;
        }

        $decision = [
            'meaningful' => true,
            'reason_code' => 'operational_delay',
            'primary_type' => 'delay',
            'types' => ['delay'],
            'role' => 'action',
            'transition' => 'updated',
            'summary' => $text,
            'confidence' => 'medium',
            'uncertainty' => null,
            'subject_key' => null,
            'is_question' => false,
        ];

        return $this->delayCandidate($message, $decision) !== null ? $decision : null;
    }

    private function delayCandidate(TelegramMessage $message, array $decision): ?TelegramOperationalEvent
    {
        if ($message->telegram_user_id === null || $decision['primary_type'] !== 'delay'
            && ! ($decision['primary_type'] === 'action'
                && preg_match('/^уже\s+еду[.!]?$/ui', (string) $decision['summary']) === 1)) {
            return null;
        }

        // A repeated generic delay is not enough evidence that it is the same occurrence.
        if ($decision['primary_type'] === 'delay' && $this->delayMinutes((string) $decision['summary']) === null) {
            return null;
        }

        if ($decision['role'] === 'report' && ! $this->isPersonalDelayText((string) $decision['summary'])) {
            return null;
        }

        $candidates = TelegramOperationalEvent::query()
            ->where('telegram_chat_id', $message->telegram_chat_id)
            ->where('primary_type', 'delay')
            ->whereIn('status', ['open', 'reopened'])
            ->whereBetween('last_observed_at', [
                $this->messageTime($message)->subMinutes(45),
                $this->messageTime($message),
            ])
            ->where(function ($query) use ($message): void {
                $message->telegram_topic_id === null
                    ? $query->whereNull('telegram_topic_id')
                    : $query->where('telegram_topic_id', $message->telegram_topic_id);
            })
            ->whereHas('rootMessage', fn ($query) => $query->where('telegram_user_id', $message->telegram_user_id))
            ->lockForUpdate()
            ->get()
            ->filter(fn (TelegramOperationalEvent $event): bool => $this->isPersonalDelayText(
                (string) ($event->rootMessage?->text ?: $event->rootMessage?->caption ?: '')
            ));

        return $candidates->count() === 1 ? $candidates->first() : null;
    }

    private function isPersonalDelayText(string $text): bool
    {
        return preg_match('/^(?:я\s+(?:скоро\s+)?(?:опозд|задерж|не\s+успе)|опозд|задержусь|не\s+успе)/ui', trim($text)) === 1;
    }

    private function delayMinutes(string $text): ?int
    {
        if (preg_match('/(?:минут\s+через\s+|через\s+|на\s+)(\d{1,3})\s*(?:мин(?:ут[ыу]?)?)?/ui', $text, $matches) === 1
            && preg_match('/минут|мин\b/ui', $text) === 1) {
            return (int) $matches[1];
        }

        return null;
    }

    private function requiresExistingSituation(array $decision): bool
    {
        return in_array($decision['role'], ['resolution', 'action', 'commitment'], true)
            || $decision['transition'] === 'resolved';
    }

    private function completeAmbiguous(TelegramOperationalObservation $observation, Carbon $clock): array
    {
        $observation->fill([
            'state' => 'completed',
            'outcome' => 'no_event',
            'reason_code' => 'ambiguous_correlation',
            'confidence' => 'low',
            'uncertainty' => 'Несколько событий подходят; автоматическое объединение или разрешение не выполнено.',
            'processed_at' => $clock,
        ])->save();

        return $this->resultFromObservation($observation, false);
    }

    private function evaluateUnanswered(
        TelegramMessage $message,
        TelegramOperationalObservation $observation,
        Carbon $clock,
        string $revisionHash,
    ): array {
        $decision = $this->interpreter->interpret(trim((string) ($message->text ?: $message->caption ?: '')));
        $dueAt = $this->messageTime($message)->addHours(4);

        if (! $decision['meaningful'] || ! $decision['is_question']) {
            return $this->completeNoEvent($observation, 'not_operational_question', $clock);
        }

        if ($clock->lt($dueAt)) {
            $observation->fill([
                'state' => 'processing',
                'outcome' => 'pending_question',
                'reason_code' => 'awaiting_response',
                'confidence' => $decision['confidence'],
                'uncertainty' => $decision['uncertainty'],
                'unanswered_due_at' => $dueAt,
            ])->save();

            return $this->result($message, $observation, $this->eventForMessageRevision($message, $revisionHash), false);
        }

        if ($this->hasMeaningfulResponse($message, $clock)) {
            return $this->completeNoEvent($observation, 'operational_question_answered', $clock);
        }

        $event = $this->eventForMessageRevision($message, $revisionHash);

        if (! $event) {
            return $this->completeNoEvent($observation, 'question_event_missing', $clock);
        }

        $types = collect($event->types)->push('unanswered_question')->unique()->values()->all();
        $before = $event->status;
        $event->forceFill([
            'types' => $types,
            'last_observed_at' => $dueAt,
        ])->save();

        TelegramOperationalEventEvidence::query()->create([
            'operational_event_id' => $event->id,
            'observation_id' => $observation->id,
            'role' => 'question',
            'transition' => 'updated',
            'status_before' => $before,
            'status_after' => $event->status,
            'confidence' => $decision['confidence'],
            'uncertainty' => $decision['uncertainty'],
            'occurred_at' => $dueAt,
            'is_current_revision' => true,
        ]);

        $observation->fill([
            'state' => 'completed',
            'outcome' => 'updated',
            'reason_code' => 'unanswered_question_matured',
            'confidence' => $decision['confidence'],
            'uncertainty' => $decision['uncertainty'],
            'unanswered_due_at' => $dueAt,
            'processed_at' => $clock,
        ])->save();

        return $this->result($message, $observation, $event, false);
    }

    private function hasMeaningfulResponse(TelegramMessage $question, Carbon $clock): bool
    {
        $query = TelegramMessage::query()
            ->where('telegram_chat_id', $question->telegram_chat_id)
            ->where('id', '!=', $question->id)
            ->where('sent_at', '>=', $this->messageTime($question))
            ->where('sent_at', '<=', $clock)
            ->orderBy('sent_at')
            ->orderBy('id');

        if ($question->telegram_topic_id !== null) {
            $query->where('telegram_topic_id', $question->telegram_topic_id);
        }

        return $query->get()->contains(function (TelegramMessage $candidate) use ($question): bool {
            $raw = $this->normalizedRaw($candidate->raw);
            $payload = $this->messagePayload($raw);
            $replyTo = data_get($payload, 'reply_to_message.message_id');
            $decision = $this->interpreter->interpret(trim((string) ($candidate->text ?: $candidate->caption ?: '')));

            if ((string) $replyTo === (string) $question->message_id) {
                $hasAnswerContent = trim((string) ($candidate->text ?: $candidate->caption ?: '')) !== ''
                    || $candidate->attachments()->exists();

                return $hasAnswerContent
                    && ! $decision['is_question']
                    && data_get($payload, 'from.is_bot') !== true;
            }

            return $decision['meaningful']
                && ! $decision['is_question']
                && in_array($decision['role'], ['commitment', 'action', 'resolution'], true);
        });
    }

    private function eventForMessageRevision(TelegramMessage $message, string $revisionHash): ?TelegramOperationalEvent
    {
        return TelegramOperationalEvent::query()
            ->whereHas('evidence.observation', function ($query) use ($message, $revisionHash) {
                $query
                    ->where('telegram_message_id', $message->id)
                    ->where('source_revision_hash', $revisionHash)
                    ->where('evaluation_kind', 'message');
            })
            ->first();
    }

    private function completeNoEvent(
        TelegramOperationalObservation $observation,
        string $reasonCode,
        Carbon $clock,
    ): array {
        $observation->fill([
            'state' => 'completed',
            'outcome' => 'no_event',
            'reason_code' => $reasonCode,
            'processed_at' => $clock,
        ])->save();

        return $this->resultFromObservation($observation, false);
    }

    private function completeNoEventWithCorrection(
        TelegramMessage $message,
        TelegramOperationalObservation $observation,
        string $reasonCode,
        Carbon $clock,
    ): array {
        $events = $this->previousRevisionEvents($message, $observation);

        if ($events->isEmpty()) {
            return $this->completeNoEvent($observation, $reasonCode, $clock);
        }

        $this->markPreviousRevisionSuperseded($message, $observation);
        $dismissedEvent = null;

        foreach ($events as $event) {
            $hasCurrentSupport = $event->evidence()
                ->where('is_current_revision', true)
                ->where('role', '!=', 'correction')
                ->exists();
            $before = $event->status;
            $after = $hasCurrentSupport ? $before : 'dismissed';

            $event->forceFill([
                'status' => $after,
                'last_observed_at' => $this->messageTime($message),
                'resolved_at' => $after === 'resolved' ? $event->resolved_at : null,
            ])->save();

            TelegramOperationalEventEvidence::query()->create([
                'operational_event_id' => $event->id,
                'observation_id' => $observation->id,
                'role' => 'correction',
                'transition' => $hasCurrentSupport ? 'evidence' : 'dismissed',
                'status_before' => $before,
                'status_after' => $after,
                'confidence' => 'high',
                'uncertainty' => null,
                'occurred_at' => $this->messageTime($message),
                'is_current_revision' => true,
            ]);

            $event = $this->reprojectEvent($event);

            if (! $hasCurrentSupport) {
                $dismissedEvent = $event;
            }
        }

        $observation->fill([
            'state' => 'completed',
            'outcome' => $dismissedEvent ? 'dismissed' : 'no_event',
            'reason_code' => $reasonCode,
            'confidence' => $dismissedEvent ? 'high' : null,
            'processed_at' => $clock,
        ])->save();

        return $this->result($message, $observation, $dismissedEvent, false);
    }

    private function reprojectEvent(TelegramOperationalEvent $event): TelegramOperationalEvent
    {
        $evidence = $event->evidence()
            ->with('observation.message')
            ->where('is_current_revision', true)
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get();
        $support = $evidence->where('role', '!=', 'correction')->values();

        if ($support->isEmpty()) {
            $last = $evidence->last();
            $event->forceFill([
                'status' => 'dismissed',
                'first_observed_at' => $last?->occurred_at ?: $event->first_observed_at,
                'last_observed_at' => $last?->occurred_at ?: $event->last_observed_at,
                'resolved_at' => null,
            ])->save();

            return $event;
        }

        $decisions = $support->map(function (TelegramOperationalEventEvidence $item): array {
            $message = $item->observation->message;

            return $this->interpreter->interpret(trim((string) ($message?->text ?: $message?->caption ?: '')));
        });
        $types = $decisions->flatMap(fn (array $decision) => $decision['types'])->filter()->unique()->values();

        if ($support->contains(fn (TelegramOperationalEventEvidence $item) => $item->observation->reason_code === 'unanswered_question_matured')) {
            $types->push('unanswered_question');
        }

        $firstDecision = $decisions->first(fn (array $decision) => $decision['meaningful']);
        $confidence = $decisions->contains(fn (array $decision) => $decision['confidence'] === 'low')
            ? 'low'
            : ($decisions->contains(fn (array $decision) => $decision['confidence'] === 'medium') ? 'medium' : 'high');
        $uncertainty = $decisions->pluck('uncertainty')->filter()->unique()->implode(' ');
        $lastEvidence = $evidence->last();
        $status = $lastEvidence?->status_after ?: $event->status;
        $delayDetail = $event->primary_type === 'delay'
            ? $support->map(fn (TelegramOperationalEventEvidence $item): ?int => $this->delayMinutes(
                (string) ($item->observation->message?->text ?: $item->observation->message?->caption ?: '')
            ))->filter(fn (?int $minutes): bool => $minutes !== null)->last()
            : null;

        $event->forceFill([
            'primary_type' => $firstDecision['primary_type'] ?? $event->primary_type,
            'types' => $types->isNotEmpty() ? $types->unique()->values()->all() : $event->types,
            'summary' => $delayDetail !== null
                ? 'Задержка примерно на '.$delayDetail.' минут.'
                : ($firstDecision['summary'] ?? $event->summary),
            'status' => $status,
            'confidence' => $confidence,
            'uncertainty' => $uncertainty !== '' ? $uncertainty : null,
            'first_observed_at' => $evidence->first()?->occurred_at ?: $event->first_observed_at,
            'last_observed_at' => $lastEvidence?->occurred_at ?: $event->last_observed_at,
            'resolved_at' => $status === 'resolved'
                ? $support->last(fn (TelegramOperationalEventEvidence $item) => $item->transition === 'resolved')?->occurred_at
                : null,
        ])->save();

        return $event;
    }

    private function previousRevisionEvents(
        TelegramMessage $message,
        TelegramOperationalObservation $current,
    ): Collection {
        return TelegramOperationalEvent::query()
            ->whereHas('evidence', fn ($query) => $query
                ->where('is_current_revision', true)
                ->whereHas('observation', fn ($observationQuery) => $observationQuery
                    ->where('telegram_message_id', $message->id)
                    ->where('evaluation_kind', $current->evaluation_kind)
                    ->whereKeyNot($current->id)))
            ->lockForUpdate()
            ->get();
    }

    private function markPreviousRevisionSuperseded(
        TelegramMessage $message,
        TelegramOperationalObservation $current,
    ): void {
        $previousIds = TelegramOperationalObservation::query()
            ->where('telegram_message_id', $message->id)
            ->where('evaluation_kind', $current->evaluation_kind)
            ->whereKeyNot($current->id)
            ->where('is_current_revision', true)
            ->pluck('id');

        if ($previousIds->isEmpty()) {
            return;
        }

        TelegramOperationalObservation::query()
            ->whereKey($previousIds)
            ->update(['is_current_revision' => false]);
        TelegramOperationalEventEvidence::query()
            ->whereIn('observation_id', $previousIds)
            ->update(['is_current_revision' => false]);
    }

    private function eligibilityReason(TelegramMessage $message, array $raw): ?string
    {
        $chatType = $message->chat?->type;
        $chatId = (string) ($message->chat?->telegram_chat_id ?? '');
        $allowedChatIds = array_map('strval', config('services.telegram.operational_chat_ids', []));

        if ($chatType === 'private' || ($allowedChatIds !== [] && ! in_array($chatId, $allowedChatIds, true))) {
            return 'private_or_disallowed_chat';
        }

        $payload = $this->messagePayload($raw);

        if (data_get($payload, 'from.is_bot') === true) {
            return 'bot_or_service_message';
        }

        if (! in_array($message->message_type, ['text', 'photo', 'video', 'document'], true)) {
            return 'unsupported_message_type';
        }

        if (trim((string) ($message->text ?: $message->caption ?: '')) === '') {
            return 'empty_content';
        }

        return null;
    }

    private function revisionHash(TelegramMessage $message, array $raw): string
    {
        $payload = $this->messagePayload($raw);

        return hash('sha256', json_encode([
            'message_id' => (string) $message->message_id,
            'chat_id' => $message->telegram_chat_id,
            'topic_id' => $message->telegram_topic_id,
            'user_id' => $message->telegram_user_id,
            'message_type' => $message->message_type,
            'text' => $message->text,
            'caption' => $message->caption,
            'edited_at' => $message->edited_at?->toIso8601String(),
            'reply_to_message_id' => data_get($payload, 'reply_to_message.message_id'),
            'is_bot' => data_get($payload, 'from.is_bot'),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function normalizedRaw(mixed $raw): array
    {
        $value = $raw;

        for ($attempt = 0; $attempt < 2 && is_string($value); $attempt++) {
            $decoded = json_decode($value, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                return [];
            }

            $value = $decoded;
        }

        return is_array($value) ? $value : [];
    }

    private function messagePayload(array $raw): array
    {
        foreach (['message', 'edited_message', 'channel_post', 'edited_channel_post'] as $key) {
            if (isset($raw[$key]) && is_array($raw[$key])) {
                return $raw[$key];
            }
        }

        return $raw;
    }

    private function messageTime(TelegramMessage $message): Carbon
    {
        return ($message->sent_at ?: $message->created_at ?: now())->copy();
    }

    private function resultFromObservation(
        TelegramOperationalObservation $observation,
        bool $idempotentReuse,
    ): array {
        $observation->loadMissing('evidence.event');
        $event = $observation->evidence->first()?->event;
        $message = $observation->message ?: TelegramMessage::findOrFail($observation->telegram_message_id);

        return $this->result($message, $observation, $event, $idempotentReuse);
    }

    private function result(
        TelegramMessage $message,
        TelegramOperationalObservation $observation,
        ?TelegramOperationalEvent $event,
        bool $idempotentReuse,
    ): array {
        return [
            'message_id' => $message->id,
            'revision' => 'sha256:'.$observation->source_revision_hash,
            'evaluation_kind' => $observation->evaluation_kind,
            'outcome' => $observation->outcome,
            'reason_code' => $observation->reason_code,
            'event_key' => $event?->event_key,
            'event_status' => $event?->status,
            'event_types' => $event?->types ?? [],
            'confidence' => $observation->confidence,
            'uncertainty' => $observation->uncertainty,
            'idempotent_reuse' => $idempotentReuse,
            'unanswered_due_at' => $observation->unanswered_due_at?->toIso8601String(),
        ];
    }
}
