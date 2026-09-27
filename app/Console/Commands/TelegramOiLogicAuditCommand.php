<?php

namespace App\Console\Commands;

use App\Models\TelegramMessage;
use App\Models\TelegramOperationalEvent;
use App\Models\TelegramOperationalEventEvidence;
use App\Models\User;
use App\Services\Telegram\TelegramDistrictRouteRegistry;
use App\Services\Telegram\TelegramEveningIntelligenceBuilder;
use App\Services\Telegram\TelegramTopicPresenter;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Throwable;

class TelegramOiLogicAuditCommand extends Command
{
    protected $signature = 'telegram:oi-logic-audit
        {--date= : Required date in YYYY-MM-DD format}
        {--district= : Optional configured district key}
        {--event= : Optional ledger event ID or exact event key}
        {--apartment= : Optional apartment ID}
        {--json : Emit machine-readable audit data}';

    protected $description = 'Inspect existing OI decisions, evidence, lifecycle, and bounded Telegram context without writes';

    private const CONTEXT_MINUTES = 15;

    private const CONTEXT_LIMIT = 80;

    public function handle(
        TelegramEveningIntelligenceBuilder $builder,
        TelegramDistrictRouteRegistry $districts,
        TelegramTopicPresenter $topics,
    ): int {
        $date = $this->dateOption();

        if ($date === null) {
            return self::FAILURE;
        }

        $district = null;

        if (filled($this->option('district'))) {
            $district = $districts->find((string) $this->option('district'));

            if ($district === null) {
                $this->error('District route is not configured or is incomplete.');

                return self::FAILURE;
            }
        }

        try {
            // This is the same projection used by the existing evening preview.
            $preview = $builder->build($date, ['district' => $district]);
            $audit = $this->audit($preview, $date, $topics);
} catch (Throwable $e) {
    report($e);

    $this->error('Operational Intelligence audit could not read the ledger and Telegram context.');

    if ($this->getOutput()->isVerbose()) {
        $this->line($e::class.': '.$e->getMessage());
    }

    return self::FAILURE;
}

        if ($this->option('json')) {
            $this->line(json_encode(
                $audit,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            ));

            return self::SUCCESS;
        }

        $this->render($audit);

        return self::SUCCESS;
    }

    /** @return array<string, mixed> */
    private function audit(array $preview, Carbon $date, TelegramTopicPresenter $topics): array
    {
        $items = collect($preview['events'] ?? []);
        $start = $date->copy()->startOfDay();
        $now = now($preview['timezone'] ?? config('app.timezone', 'Europe/Rome'));
        $cutoff = $date->isSameDay($now) ? $now : $date->copy()->endOfDay();
        $eventQuery = TelegramOperationalEvent::query()
            ->with([
                'chat:id,telegram_chat_id,title',
                'topic:id,telegram_chat_id,telegram_thread_id,title,apartment_id',
                'topic.apartment:id,name',
                'apartment:id,name',
                'evidence' => fn ($query) => $query
                    ->where('is_current_revision', true)
                    ->where('occurred_at', '<=', $cutoff)
                    ->with(['observation.message.telegramUser', 'observation.message.attachments'])
                    ->orderBy('occurred_at')
                    ->orderBy('id'),
            ]);

        $eventQuery->where(function ($query) use ($items, $start, $cutoff): void {
            $query->whereIn('event_key', $items->pluck('event_key')->filter()->all())
                ->orWhereBetween('first_observed_at', [$start, $cutoff])
                ->orWhereHas('evidence', fn ($evidence) => $evidence
                    ->where('is_current_revision', true)
                    ->whereBetween('occurred_at', [$start, $cutoff]));
        });

        $district = $preview['district']['source_chat_id'] ?? null;

        if (filled($district)) {
            $eventQuery->whereHas('chat', fn ($chat) => $chat->where('telegram_chat_id', (string) $district));
        }

        if (filled($this->option('apartment'))) {
            $apartmentId = (int) $this->option('apartment');
            $eventQuery->where(fn ($query) => $query
                ->where('apartment_id', $apartmentId)
                ->orWhereHas('topic', fn ($topic) => $topic->where('apartment_id', $apartmentId)));
        }

        $events = $eventQuery->get()->keyBy('event_key');

        if (filled($this->option('event'))) {
            $requested = (string) $this->option('event');
            $events = $events->filter(fn (TelegramOperationalEvent $event): bool => $event->event_key === $requested
                || (ctype_digit($requested) && (string) $event->getKey() === $requested));
        }

        $itemsByKey = $items->keyBy('event_key');
        $items = $events->map(function (TelegramOperationalEvent $event) use ($itemsByKey): array {
            if ($itemsByKey->has($event->event_key)) {
                return $itemsByKey->get($event->event_key);
            }

            $status = $event->evidence->last()?->status_after;

            return [
                'event_key' => $event->event_key,
                'summary' => $event->summary,
                'types' => array_values(array_unique(array_merge([$event->primary_type], $event->types ?? []))),
                'status' => $status,
                'confidence' => $event->evidence->last()?->confidence,
                'uncertainty' => $event->evidence->isEmpty()
                    ? 'No current-revision source evidence is available at the selected-date cutoff.'
                    : $event->evidence->pluck('uncertainty')->filter()->unique()->implode(' '),
                'editorial' => [
                    'relevant_today' => false,
                    'state' => 'omit',
                    'needs_attention' => false,
                    'next_action' => null,
                    'render_outcome' => ['omit'],
                    'summary' => null,
                    'resolution' => null,
                ],
            ];
        })->values();

        if (filled($this->option('event'))) {
            $requested = (string) $this->option('event');
            $items = $items->filter(function (array $item) use ($requested, $events): bool {
                $event = $events->get($item['event_key'] ?? '');

                return (string) ($item['event_key'] ?? '') === $requested
                    || (ctype_digit($requested) && (string) $event?->getKey() === $requested);
            })->values();
        }

        $sectionItems = collect($preview['editorial_sections'] ?? [])
            ->flatMap(fn (array $section): array => $section['items'] ?? [])
            ->groupBy('event_key');
        $linkedNames = $this->linkedUserNames($events->values());

        $audited = $items->map(function (array $item) use ($events, $sectionItems, $linkedNames): array {
            /** @var TelegramOperationalEvent|null $event */
            $event = $events->get($item['event_key'] ?? '');

            if ($event === null) {
                return [];
            }

            $messages = $event->evidence
                ->map(fn (TelegramOperationalEventEvidence $evidence) => $evidence->observation?->message)
                ->filter()
                ->keyBy('id');
            $selected = $sectionItems->get($event->event_key, collect())
                ->first(fn (array $line): bool => filled($line['quote'] ?? null));
            $selectedEvidence = $this->selectedEvidence($selected, $event->evidence, $messages);
            $evidence = $event->evidence->map(function (TelegramOperationalEventEvidence $record) use ($linkedNames): array {
                $message = $record->observation?->message;

                return [
                    'role' => $record->role,
                    'transition' => $record->transition,
                    'status_before' => $record->status_before,
                    'status_after' => $record->status_after,
                    'occurred_at' => $record->occurred_at?->toIso8601String(),
                    'current_revision' => $record->is_current_revision,
                    'message' => $message ? $this->messagePayload($message, $linkedNames) : null,
                ];
            })->values();

            $conversation = $this->relatedConversation($event, $messages, $linkedNames);
            $apartment = $event->apartment?->name ?: $event->topic?->apartment?->name;
            $editorial = $item['editorial'] ?? [];
            $renderedIn = collect($editorial['render_outcome'] ?? [])
                ->map(fn (string $key): string => match ($key) {
                    'day' => 'За день',
                    'resolved' => 'Решено сегодня',
                    'positive' => 'Хорошая работа',
                    'attention' => 'Требует внимания',
                    'omit' => 'none',
                    default => $key,
                })
                ->all();

            if (($editorial['needs_attention'] ?? false) && filled($editorial['next_action'] ?? null)) {
                $renderedIn[] = 'Осталось сделать';
            }

            return [
                'id' => $event->getKey(),
                'event_key' => $event->event_key,
                'district' => $event->chat ? $topics->chatLabel($event->chat) : null,
                'apartment' => $apartment,
                'event_apartment' => $event->apartment?->name,
                'topic_apartment' => $event->topic?->apartment?->name,
                'apartment_id' => $event->apartment_id,
                'topic_apartment_id' => $event->topic?->apartment_id,
                'topic' => $event->topic ? $topics->title($event->topic) : null,
                'telegram_thread_id' => $event->topic?->telegram_thread_id,
                'time' => $event->first_observed_at?->toIso8601String(),
                'current_oi' => [
                    'primary_type' => $event->primary_type,
                    'types' => $item['types'] ?? [],
                    'state' => $item['status'] ?? null,
                    'summary' => $item['summary'] ?? $event->summary,
                    'confidence' => $item['confidence'] ?? null,
                    'uncertainty' => $item['uncertainty'] ?? null,
                    'needs_attention' => (bool) ($editorial['needs_attention'] ?? false),
                    'next_action' => $editorial['next_action'] ?? null,
                    'author' => $selected['author_name'] ?? null,
                    'selected_quote' => $selected['quote'] ?? null,
                    'selected_evidence' => $selectedEvidence,
                    'resolution' => $editorial['resolution'] ?? null,
                    'resolved_at' => $this->resolvedAtAtCutoff($event),
                    'relevant_today' => (bool) ($editorial['relevant_today'] ?? false),
                    'editorial_state' => $editorial['state'] ?? 'omit',
                    'rendered_in' => $renderedIn,
                    'editorial_summary' => $editorial['summary'] ?? null,
                ],
                'evidence' => $evidence->all(),
                'related_conversation' => $conversation,
            ];
        })->filter()->values();

        $resolvedCount = $audited->filter(fn (array $event): bool => ($event['current_oi']['state'] ?? null) === 'resolved')->count();
        $unresolvedCount = $audited->filter(fn (array $event): bool => in_array($event['current_oi']['state'] ?? null, ['open', 'reopened'], true))->count();
        $needsAttentionCount = $audited->filter(fn (array $event): bool => $event['current_oi']['needs_attention'])->count();

        return [
            'date' => $preview['date'] ?? $date->toDateString(),
            'timezone' => $preview['timezone'] ?? config('app.timezone', 'Europe/Rome'),
            'events' => $audited->all(),
            'counts' => [
                'events_inspected' => $audited->count(),
                'resolved' => $resolvedCount,
                'unresolved' => $unresolvedCount,
                'unknown_lifecycle' => $audited->count() - $resolvedCount - $unresolvedCount,
                'needs_attention' => $needsAttentionCount,
                'missing_apartment' => $audited->whereNull('apartment')->count(),
                'missing_evidence' => $audited->filter(fn (array $event): bool => $event['evidence'] === [])->count(),
                'missing_author' => $audited->filter(fn (array $event): bool => blank($event['current_oi']['author']))->count(),
            ],
            'mode' => ['read_only' => true, 'ledger_mutations' => 0, 'telegram_actions' => 0],
        ];
    }

    private function resolvedAtAtCutoff(TelegramOperationalEvent $event): ?string
    {
        $resolution = $event->evidence
            ->filter(fn (TelegramOperationalEventEvidence $evidence): bool => $evidence->transition === 'resolved'
                && $evidence->status_after === 'resolved')
            ->last();

        return $resolution?->occurred_at?->toIso8601String();
    }

    /** @param Collection<int, TelegramOperationalEvent> $events */
    private function linkedUserNames(Collection $events): Collection
    {
        $ids = $events
            ->flatMap(fn (TelegramOperationalEvent $event) => $event->evidence
                ->map(fn (TelegramOperationalEventEvidence $evidence) => $evidence->observation?->message?->telegramUser?->linked_user_id))
            ->filter()
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return collect();
        }

        return User::query()->whereIn('id', $ids)->get(['id', 'name'])->keyBy('id');
    }

    /** @return array<string, mixed>|null */
    private function selectedEvidence(?array $selected, Collection $evidence, Collection $messages): ?array
    {
        if (! filled($selected['quote'] ?? null)) {
            return null;
        }

        $quote = $this->normalizeText((string) $selected['quote']);
        $matches = $evidence->filter(function (TelegramOperationalEventEvidence $record) use ($messages, $quote): bool {
            $message = $messages->get($record->observation?->telegram_message_id);
            $text = $this->normalizeText((string) ($message?->text ?: $message?->caption ?: ''));

            return $text !== '' && (str_contains($text, rtrim($quote, '…')) || str_contains($quote, $text));
        })->values();
        $match = $matches->count() === 1 ? $matches->first() : null;

        return [
            'local_message_id' => $match?->observation?->telegram_message_id,
            'telegram_message_id' => $match?->observation?->message?->message_id,
            'role' => $match?->role,
            'quote' => $selected['quote'],
            'author' => $selected['author_name'] ?? null,
            'message_match_count' => $matches->count(),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function relatedConversation(
        TelegramOperationalEvent $event,
        Collection $evidenceMessages,
        Collection $linkedNames,
    ): array {
        if ($evidenceMessages->isEmpty()) {
            return [];
        }

        $chatId = $event->telegram_chat_id;
        $topicId = $event->telegram_topic_id;
        $timestamps = $evidenceMessages->pluck('sent_at')->filter();

        if ($timestamps->isEmpty()) {
            return $evidenceMessages->map(fn (TelegramMessage $message): array => $this->messagePayload($message, $linkedNames))->values()->all();
        }

        $windows = $timestamps->map(fn (CarbonInterface $time): array => [
            $time->copy()->subMinutes(self::CONTEXT_MINUTES),
            $time->copy()->addMinutes(self::CONTEXT_MINUTES),
        ]);
        $query = TelegramMessage::query()
            ->where('telegram_chat_id', $chatId)
            ->where('telegram_topic_id', $topicId);
        $query->where(function ($query) use ($windows): void {
            foreach ($windows as [$from, $to]) {
                $query->orWhereBetween('sent_at', [$from, $to]);
            }
        });

        $nearby = $query
            ->with(['telegramUser', 'attachments'])
            ->orderBy('sent_at')
            ->limit(self::CONTEXT_LIMIT)
            ->get();

        // Add replied-to parent messages referenced by evidence, but keep them in the exact same chat/topic.
        $replyIds = $evidenceMessages
            ->map(fn (TelegramMessage $message): mixed => data_get($message->raw, 'message.reply_to_message.message_id'))
            ->filter()
            ->map(fn (mixed $id): string => (string) $id)
            ->unique()
            ->values();

        $nearbyReplyIds = $nearby
            ->map(fn (TelegramMessage $message): mixed => data_get($message->raw, 'message.reply_to_message.message_id'))
            ->filter()
            ->map(fn (mixed $id): string => (string) $id)
            ->merge($replyIds)
            ->unique()
            ->values();

        $parents = $nearbyReplyIds->isEmpty()
            ? collect()
            : TelegramMessage::query()
                ->where('telegram_chat_id', $chatId)
                ->where('telegram_topic_id', $topicId)
                ->whereIn('message_id', $nearbyReplyIds)
                ->with(['telegramUser', 'attachments'])
                ->get();

        return $nearby
            ->merge($parents)
            ->merge($evidenceMessages)
            ->unique('id')
            ->sortBy(fn (TelegramMessage $message): array => [$message->sent_at?->timestamp ?? 0, (int) $message->id])
            ->take(self::CONTEXT_LIMIT)
            ->pipe(function (Collection $messages) use ($linkedNames): Collection {
                $linkedIds = $messages->map(fn (TelegramMessage $message): mixed => $message->telegramUser?->linked_user_id)
                    ->filter()
                    ->unique()
                    ->values();
                $contextNames = $linkedIds->isEmpty()
                    ? collect()
                    : User::query()->whereIn('id', $linkedIds)->get(['id', 'name'])->keyBy('id');

                return $messages->map(fn (TelegramMessage $message): array => $this->messagePayload(
                    $message,
                    $contextNames->has($message->telegramUser?->linked_user_id) ? $contextNames : $linkedNames,
                ));
            })
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    private function messagePayload(TelegramMessage $message, Collection $linkedNames): array
    {
        $telegramUser = $message->telegramUser;
        $linkedName = $linkedNames->get($telegramUser?->linked_user_id)?->name;
        $telegramName = trim((string) ($telegramUser?->full_name ?: trim(($telegramUser?->first_name ?? '').' '.($telegramUser?->last_name ?? ''))));
        $replyTo = data_get($message->raw, 'message.reply_to_message.message_id');

        return [
            'time' => $message->sent_at?->toIso8601String(),
            'author' => filled($linkedName) ? $linkedName : ($telegramName !== '' ? $telegramName : null),
            'local_message_id' => $message->getKey(),
            'telegram_message_id' => $message->message_id,
            'reply_to_message_id' => $replyTo === null ? null : (string) $replyTo,
            'text' => $message->text ?: $message->caption,
            'message_type' => $message->message_type,
            'media' => $message->attachments
                ->map(fn ($attachment): array => [
                    'type' => $attachment->type,
                    'mime_type' => $attachment->mime_type,
                    'file_name' => $attachment->file_name,
                ])
                ->values()
                ->all(),
        ];
    }

    private function normalizeText(string $text): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', strip_tags($text)) ?: ''));
    }

    /** @param array<string, mixed> $audit */
    private function render(array $audit): void
    {
        foreach ($audit['events'] as $event) {
            $this->line(str_repeat('=', 50));
            $this->line('EVENT #'.$event['id']);
            $this->line('District: '.($event['district'] ?: '—'));
            $this->line('Apartment: '.($event['apartment'] ?: '—'));
            if ($event['event_apartment'] !== $event['topic_apartment']) {
                $this->line('Apartment mapping (event/topic): '.($event['event_apartment'] ?: '—').' / '.($event['topic_apartment'] ?: '—'));
            }
            $this->line('Topic: '.($event['topic'] ?: '—'));
            $this->line('Time: '.($event['time'] ?: '—'));
            $this->newLine();
            $this->info('CURRENT OI');
            $this->line('Type/domain: '.($event['current_oi']['primary_type'] ?: '—').' / '.implode(', ', $event['current_oi']['types']));
            $this->line('State: '.($event['current_oi']['state'] ?: '—'));
            $this->line('Summary: '.($event['current_oi']['summary'] ?: '—'));
            $this->line('Editorial summary: '.($event['current_oi']['editorial_summary'] ?: '—'));
            $this->line('Confidence: '.($event['current_oi']['confidence'] ?: '—'));
            $this->line('Needs attention: '.($event['current_oi']['needs_attention'] ? 'yes' : 'no'));
            $this->line('Next action: '.($event['current_oi']['next_action'] ?: '—'));
            $this->line('Author: '.($event['current_oi']['author'] ?: '—'));
            $this->line('Selected quote: '.($event['current_oi']['selected_quote'] ? '«'.$event['current_oi']['selected_quote'].'»' : '—'));
            $selectedEvidence = $event['current_oi']['selected_evidence'];
            $this->line('Selected evidence message: '.($selectedEvidence['telegram_message_id'] ?? 'unmatched/ambiguous'));
            $this->line('Resolution: '.($event['current_oi']['resolution'] ?: '—'));
            $this->line('Resolved at: '.($event['current_oi']['resolved_at'] ?: '—'));
            $this->newLine();
            $this->info('SOURCE EVIDENCE');
            $selectedMessageId = $event['current_oi']['selected_evidence']['telegram_message_id'] ?? null;
            foreach ($event['evidence'] as $record) {
                $message = $record['message'] ?? [];
                $selectedMarker = (string) ($message['telegram_message_id'] ?? '') === (string) $selectedMessageId
                    ? ' [SELECTED BY OI]'
                    : '';
                $this->line(sprintf(
                    '[%s] %s (%s, Telegram message %s, %s → %s)%s: %s',
                    $record['occurred_at'] ?: 'time unknown',
                    $message['author'] ?? 'Unknown author',
                    $record['role'],
                    $message['telegram_message_id'] ?? '—',
                    $record['transition'],
                    $record['status_after'],
                    $selectedMarker,
                    ($message['text'] ?? null) ?: '['.($message['message_type'] ?? 'unknown').']',
                ));
            }
            $this->newLine();
            $this->info('RELATED CONVERSATION');
            foreach ($event['related_conversation'] as $message) {
                $this->line(sprintf(
                    '[%s] %s (message %s, reply to %s): %s%s',
                    $message['time'] ?: 'time unknown',
                    $message['author'] ?: 'Unknown author',
                    $message['telegram_message_id'] ?: '—',
                    $message['reply_to_message_id'] ?: '—',
                    ($message['text'] ?? null) ?: '['.($message['message_type'] ?? 'unknown').']',
                    $message['media'] ? ' [media: '.implode(', ', collect($message['media'])->pluck('type')->all()).']' : '',
                ));
            }
            $this->newLine();
            $this->info('EDITORIAL OUTPUT');
            $this->line('Relevant today: '.($event['current_oi']['relevant_today'] ? 'yes' : 'no'));
            $this->line('Needs attention: '.($event['current_oi']['needs_attention'] ? 'yes' : 'no'));
            $this->line('Rendered in: '.implode(', ', $event['current_oi']['rendered_in'] ?: ['none']));
        }

        $this->line(str_repeat('=', 50));
        $this->info('SUMMARY');
        foreach ($audit['counts'] as $label => $count) {
            $this->line(str_replace('_', ' ', ucfirst($label)).': '.$count);
        }
        $this->line('Read-only: yes. Ledger mutations: 0. Telegram actions: 0.');
    }

    private function dateOption(): ?Carbon
    {
        if (! filled($this->option('date'))) {
            $this->error('Date is required and must use YYYY-MM-DD.');

            return null;
        }

        $value = (string) $this->option('date');

        try {
            $date = Carbon::createFromFormat('!Y-m-d', $value, config('app.timezone', 'Europe/Rome'));
        } catch (Throwable) {
            $this->error('Date must use YYYY-MM-DD.');

            return null;
        }

        if (! $date instanceof Carbon || $date->format('Y-m-d') !== $value) {
            $this->error('Date must be a valid YYYY-MM-DD date.');

            return null;
        }

        return $date;
    }
}
