<?php

namespace App\Services\Telegram;

use App\Models\TelegramOperationalEvent;
use App\Models\TelegramOperationalEventEvidence;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

class TelegramEveningIntelligenceBuilder
{
    public function __construct(
        private readonly TelegramTopicPresenter $topicPresenter,
        private readonly TelegramOperationalEventLifecyclePolicy $lifecyclePolicy,
        private readonly TelegramEveningHumanComposer $humanComposer,
    ) {}

    private const REASON_TYPES = [
        'operational_problem' => 'problem',
        'operational_risk' => 'risk',
        'operational_request' => 'request',
        'operational_question' => 'request',
        'operational_commitment' => 'commitment',
        'operational_action' => 'action',
        'operational_resolution' => 'resolution',
        'operational_delay' => 'delay',
        'quality_issue' => 'quality_issue',
        'positive_contribution' => 'positive_contribution',
        'unanswered_question_matured' => 'unanswered_question',
    ];

    private const TYPE_PRIORITY = [
        'risk' => 0,
        'delay' => 1,
        'unanswered_question' => 2,
        'quality_issue' => 3,
        'problem' => 4,
        'request' => 5,
        'commitment' => 6,
        'action' => 7,
        'resolution' => 8,
        'positive_contribution' => 9,
    ];

    private const ATTENTION_TYPES = [
        'problem',
        'risk',
        'delay',
        'unanswered_question',
        'quality_issue',
        'request',
        'commitment',
        'action',
    ];

    private const LOW_CONFIDENCE_TYPES = [
        'problem',
        'risk',
        'delay',
        'unanswered_question',
        'quality_issue',
    ];

    private const SUMMARY_TYPES = [
        'problem',
        'risk',
        'delay',
        'unanswered_question',
        'quality_issue',
        'positive_contribution',
    ];

    public function build(Carbon|string $date, array $options = []): array
    {
        if (! Schema::connection('analytics')->hasColumn('telegram_topics', 'apartment_id')
            || ! Schema::connection('analytics')->hasColumn('telegram_operational_events', 'apartment_id')) {
            throw new RuntimeException('Analytics apartment-context schema is unavailable.');
        }

        $timezone = config('app.timezone', 'Europe/Rome');
        $day = $date instanceof Carbon
            ? $date->copy()->setTimezone($timezone)->startOfDay()
            : Carbon::parse($date, $timezone)->startOfDay();
        $start = $day->copy()->startOfDay();
        $end = $day->copy()->endOfDay();
        $now = now($timezone);
        $cutoff = $day->isSameDay($now) ? $now : $end;
        $recurrenceStart = $start->copy()->subDays(6);

        $district = is_array($options['district'] ?? null) ? $options['district'] : null;
        $events = TelegramOperationalEvent::query()
            ->when(filled($district['chat_id'] ?? null), fn ($query) => $query
                ->whereHas('chat', fn ($chat) => $chat
                    ->where('telegram_chat_id', (string) $district['chat_id'])))
            ->whereHas('evidence', fn ($query) => $query
                ->where('is_current_revision', true)
                ->where('occurred_at', '<=', $cutoff))
            ->with([
                'chat:id,telegram_chat_id,title',
                'topic:id,telegram_chat_id,telegram_thread_id,title,purpose',
                'topic.chat:id,telegram_chat_id,title',
                'apartment:id,name',
                'evidence' => fn ($query) => $query
                    ->where('is_current_revision', true)
                    ->where('occurred_at', '<=', $cutoff)
                    ->with('observation.message.telegramUser')
                    ->orderBy('occurred_at')
                    ->orderBy('id'),
            ])
            ->get();

        // Telegram analytics and application users use separate connections.
        // Reuse the assistant's existing linked-id / Telegram-id resolution read-only.
        $telegramUsers = $events
            ->flatMap(fn (TelegramOperationalEvent $event) => $event->evidence
                ->map(fn (TelegramOperationalEventEvidence $evidence) => $evidence->observation?->message?->telegramUser))
            ->filter()
            ->unique('id')
            ->values();
        $linkedUserIds = $telegramUsers->pluck('linked_user_id')->filter()->unique()->values();
        $telegramSenderIds = $telegramUsers->pluck('telegram_user_id')->filter()->unique()->values();
        $resolvedUsers = $linkedUserIds->isEmpty() && $telegramSenderIds->isEmpty()
            ? collect()
            : User::query()
                ->where(fn ($query) => $query
                    ->whereIn('id', $linkedUserIds)
                    ->orWhereIn('telegram_id', $telegramSenderIds))
                ->get(['id', 'name', 'telegram_id']);
        $linkedUserNames = $resolvedUsers->keyBy('id');
        $telegramSenderNames = $resolvedUsers->filter(fn (User $user): bool => filled($user->telegram_id))
            ->keyBy(fn (User $user): string => (string) $user->telegram_id);

        $projected = $events
            ->map(fn (TelegramOperationalEvent $event) => $this->project(
                $event,
                $start,
                $cutoff,
                $linkedUserNames,
                $telegramSenderNames,
            ))
            ->filter()
            ->values();
        $editorialItems = $projected
            ->map(fn (array $item): array => $this->editorialize($item, $start, $cutoff))
            ->values();
        $items = $projected
            ->filter(fn (array $item) => $this->shouldInclude($item))
            ->map(function (array $item): array {
                unset($item['_citation_candidates']);

                return $item;
            })
            ->values()
            ->all();
        $this->sortItems($items);

        $sections = $this->sections($items);
        $editorialSections = $this->editorialSections($editorialItems);
        $recurrences = $this->recurrences($recurrenceStart, $cutoff, $district);
        $included = collect($editorialSections)
            ->flatMap(fn (array $section) => $section['items'])
            ->pluck('event_key')
            ->unique()
            ->count();

        return [
            'date' => $day->toDateString(),
            'timezone' => $timezone,
            'district' => $district === null ? null : [
                'key' => $district['key'] ?? null,
                'label' => $district['label'] ?? null,
                'source_chat_id' => $district['chat_id'] ?? null,
            ],
            'events' => $editorialItems->map(function (array $item): array {
                unset($item['_citation_candidates']);

                return $item;
            })->all(),
            'sections' => $sections,
            'editorial_sections' => $editorialSections,
            'recurrences' => $recurrences,
            'events_considered' => $events->count(),
            'events_included' => $included,
            'events_omitted' => max(0, $events->count() - $included),
            'no_material_events' => $included === 0,
            'data_quality' => [
                'ledger' => 'available',
            ],
            'mode' => [
                'read_only' => true,
                'telegram_actions' => 0,
                'mutations' => 0,
            ],
        ];
    }

    /**
     * Find repeated durable problems from distinct ledger occurrence transitions.
     * Evidence updates and resolutions are intentionally not counted as occurrences.
     */
    private function recurrences(Carbon $start, Carbon $cutoff, ?array $district): array
    {
        $events = TelegramOperationalEvent::query()
            ->select([
                'id', 'event_key', 'telegram_chat_id', 'telegram_topic_id', 'apartment_id',
                'primary_type', 'types', 'summary', 'subject_key',
            ])
            ->whereIn('primary_type', ['problem', 'quality_issue'])
            ->when(filled($district['chat_id'] ?? null), fn ($query) => $query
                ->whereHas('chat', fn ($chat) => $chat
                    ->where('telegram_chat_id', (string) $district['chat_id'])))
            ->whereHas('evidence', fn ($query) => $query
                ->where('is_current_revision', true)
                ->whereBetween('occurred_at', [$start, $cutoff])
                ->where(function ($occurrences): void {
                    $occurrences->where(function ($created): void {
                        $created->where('transition', 'created')
                            ->where('status_after', 'open');
                    })->orWhere(function ($reopened): void {
                        $reopened->where('transition', 'reopened')
                            ->where('status_before', 'resolved')
                            ->where('status_after', 'reopened')
                            ->where('role', 'recurrence');
                    });
                }))
            ->with([
                'apartment:id,name',
                'topic:id,telegram_chat_id,telegram_thread_id,title,purpose',
                'topic.chat:id,telegram_chat_id,title',
                'evidence' => fn ($query) => $query
                    ->select([
                        'id', 'operational_event_id', 'observation_id', 'role', 'transition', 'status_before',
                        'status_after', 'confidence', 'uncertainty', 'occurred_at', 'is_current_revision',
                    ])
                    ->where('is_current_revision', true)
                    ->whereBetween('occurred_at', [$start, $cutoff])
                    ->with('observation.message')
                    ->orderBy('occurred_at')
                    ->orderBy('id'),
            ])
            ->get();

        $groups = [];

        foreach ($events as $event) {
            $family = $this->recurrenceFamily((string) $event->subject_key);
            $location = $event->apartment_id !== null
                ? 'apartment:'.$event->apartment_id
                : ($event->telegram_topic_id !== null
                    ? 'topic:'.$event->telegram_chat_id.':'.$event->telegram_topic_id
                    : null);

            if ($family === null || $location === null) {
                continue;
            }

            $occurrences = $event->evidence
                ->filter(fn (TelegramOperationalEventEvidence $item): bool => $item->occurred_at !== null)
                ->filter(function (TelegramOperationalEventEvidence $item): bool {
                    return ($item->transition === 'created' && $item->status_after === 'open')
                        || ($item->transition === 'reopened'
                            && $item->status_before === 'resolved'
                            && $item->status_after === 'reopened'
                            && $item->role === 'recurrence');
                });
            $types = collect([$event->primary_type])->merge($event->types ?? [])->unique()->values()->all();

            if (! $this->lifecyclePolicy->mayCarryOver($types, (string) $event->summary, $occurrences)) {
                continue;
            }

            $groupKey = implode('|', [$location, $event->primary_type, $family]);
            $label = $this->eventContextLabel($event);

            foreach ($occurrences as $occurrence) {
                $occurrenceKey = $event->id.':'.($occurrence->transition === 'created' ? 'created' : 'reopened:'.$occurrence->occurred_at->toIso8601String());
                $groups[$groupKey]['occurrences'][$occurrenceKey] = [
                    'event_key' => $event->event_key,
                    'transition' => $occurrence->transition,
                    'occurred_at' => $occurrence->occurred_at->toIso8601String(),
                ];
                $groups[$groupKey]['location_key'] = $location;
                $groups[$groupKey]['primary_type'] = $event->primary_type;
                $groups[$groupKey]['family'] = $family;
                $groups[$groupKey]['context_label'] ??= $label;
            }
        }

        return collect($groups)
            ->map(function (array $group) use ($start): array {
                $occurrences = collect($group['occurrences'])->sortBy('occurred_at')->values();

                return [
                    'location_key' => $group['location_key'],
                    'primary_type' => $group['primary_type'],
                    'family' => $group['family'],
                    'context_label' => $group['context_label'],
                    'count' => $occurrences->count(),
                    'window_start' => $start->toDateString(),
                    'occurrences' => $occurrences->all(),
                ];
            })
            ->filter(fn (array $group): bool => $group['count'] >= 3)
            ->sortBy(fn (array $group): string => ($group['context_label'] ?? '').'|'.$group['family'])
            ->values()
            ->all();
    }

    private function recurrenceFamily(string $subjectKey): ?string
    {
        $parts = array_values(array_filter(explode('|', $subjectKey)));
        $specific = array_values(array_intersect($parts, ['lock', 'keys', 'hood_light', 'cleaning', 'shift', 'payment', 'technical']));

        if (in_array('hood_light', $specific, true) && count($specific) === 1) {
            return 'hood_light';
        }

        if (count($specific) === 1 && $specific[0] === 'lock') {
            return 'access_lock';
        }

        if (count($specific) === 1 && $specific[0] === 'keys') {
            return 'access_keys';
        }

        return null;
    }

    private function project(
        TelegramOperationalEvent $event,
        Carbon $start,
        Carbon $cutoff,
        Collection $linkedUserNames,
        Collection $telegramSenderNames,
    ): ?array
    {
        /** @var Collection<int, TelegramOperationalEventEvidence> $evidence */
        $evidence = $event->evidence
            ->filter(fn (TelegramOperationalEventEvidence $item) => $item->observation?->message !== null)
            ->values();

        if ($evidence->isEmpty()) {
            return null;
        }

        $status = (string) ($evidence->last()->status_after ?: 'open');

        if ($status === 'dismissed') {
            return null;
        }

        $hasActivityOnDay = $evidence->contains(
            fn (TelegramOperationalEventEvidence $item): bool => $item->occurred_at !== null
                && $item->occurred_at->betweenIncluded($start, $cutoff)
        );
        $types = collect([$event->primary_type])
            ->merge($event->types ?? [])
            ->merge($evidence->map(function (TelegramOperationalEventEvidence $item): ?string {
                $reason = $item->observation?->reason_code;

                return $reason ? (self::REASON_TYPES[$reason] ?? null) : null;
            }))
            ->filter()
            ->unique()
            ->values()
            ->all();
        $openSince = $this->currentOpenPeriodStart($evidence, $status);
        $carryOver = in_array($status, ['open', 'reopened'], true)
            && $openSince?->lt($start) === true
            && $this->lifecyclePolicy->mayCarryOver($types, (string) $event->summary, $evidence);
        $attentionEligible = in_array($status, ['open', 'reopened'], true)
            && ($this->lifecyclePolicy->mayCarryOver($types, (string) $event->summary, $evidence)
                || $this->lifecyclePolicy->mayNeedAttentionToday($types, (string) $event->summary, $evidence));

        if (! $hasActivityOnDay && ! $carryOver) {
            return null;
        }
        $confidence = $this->weakestConfidence($evidence);
        $uncertainty = $evidence
            ->pluck('uncertainty')
            ->filter(fn (mixed $value) => filled($value))
            ->unique()
            ->implode(' ');
        $references = $evidence->map(function (TelegramOperationalEventEvidence $item): array {
            $message = $item->observation->message;

            return [
                'local_message_id' => $message->id,
                'telegram_message_id' => (string) $message->message_id,
                'role' => $item->role,
                'transition' => $item->transition,
                'occurred_at' => $item->occurred_at?->toIso8601String(),
            ];
        })->all();
        $citationCandidates = $evidence->map(function (TelegramOperationalEventEvidence $item) use ($event, $linkedUserNames, $telegramSenderNames): array {
            $message = $item->observation->message;
            $telegramUser = $message->telegramUser;
            $sourceText = trim((string) ($message->text ?? ''));

            if ($sourceText === '') {
                $sourceText = trim((string) ($message->caption ?? ''));
            }

            $linkedUser = $linkedUserNames->get($telegramUser?->linked_user_id)
                ?? $telegramSenderNames->get((string) ($telegramUser?->telegram_user_id ?? ''));

            return [
                'message_id' => $message->id,
                'is_root' => (int) $message->id === (int) $event->root_message_id,
                'role' => $item->role,
                'transition' => $item->transition,
                'occurred_at' => $item->occurred_at?->toIso8601String(),
                'text' => $sourceText !== '' ? $this->compact($sourceText, 1000) : null,
                'author_name' => filled($linkedUser?->name)
                    ? $this->compact((string) $linkedUser->name, 80)
                    : null,
            ];
        })->all();
        $reportCount = $evidence
            ->whereIn('role', ['report', 'recurrence'])
            ->count();
        $actor = null;

        if (in_array('positive_contribution', $types, true)) {
            $positiveMessage = $evidence->first(
                fn (TelegramOperationalEventEvidence $item): bool => $item->role === 'positive'
            )?->observation?->message;
            $text = trim((string) ($positiveMessage?->text ?: $positiveMessage?->caption ?: ''));
            $linkedUserId = $positiveMessage?->telegramUser?->linked_user_id;

            // A third-person report is evidence of the action, not evidence that its sender did it.
            if ($linkedUserId && preg_match('/^я\s+/ui', $text) === 1) {
                $actor = User::query()->find($linkedUserId, ['id', 'name']);
            }
        } elseif (in_array('delay', $types, true)) {
            $senders = $evidence->pluck('observation.message.telegram_user_id')->filter()->unique();

            if ($senders->count() === 1) {
                $report = $evidence->first(fn (TelegramOperationalEventEvidence $item): bool => $item->role === 'report')
                    ?->observation?->message;
                $linkedUserId = $report?->telegramUser?->linked_user_id;

                if ($linkedUserId && preg_match('/^(?:я\s+(?:скоро\s+)?(?:опозд|задерж|не\s+успе)|опозд|задержусь|не\s+успе)/ui', (string) $report->text) === 1) {
                    $actor = User::query()->find($linkedUserId, ['id', 'name']);
                }
            }
        }

        return [
            'event_key' => $event->event_key,
            'apartment_id' => $event->apartment_id,
            'summary' => $this->compact((string) $event->summary, 280),
            'context_label' => $this->eventContextLabel($event),
            'actor_user_id' => $actor?->id,
            'actor_name' => $actor?->name,
            'types' => $types,
            'status' => $status,
            'confidence' => $confidence,
            'uncertainty' => $uncertainty !== '' ? $this->compact($uncertainty, 400) : null,
            'repeated' => $reportCount > 1 || $evidence->contains('transition', 'reopened'),
            'carry_over' => $carryOver,
            'attention_eligible' => $attentionEligible,
            'open_since' => $carryOver ? $openSince?->toIso8601String() : null,
            'open_age_days' => $carryOver ? $this->openAgeDays($openSince, $cutoff) : null,
            'latest_activity_at' => $evidence->last()->occurred_at?->toIso8601String(),
            'evidence' => $references,
            '_citation_candidates' => $citationCandidates,
        ];
    }

    /** @return array<string, mixed> */
    private function editorialize(array $item, Carbon $start, Carbon $cutoff): array
    {
        $relevantToday = collect($item['evidence'] ?? [])->contains(function (array $evidence) use ($start, $cutoff): bool {
            if (blank($evidence['occurred_at'] ?? null)) {
                return false;
            }

            $occurredAt = Carbon::parse($evidence['occurred_at'])->setTimezone($start->getTimezone());

            return $occurredAt->betweenIncluded($start, $cutoff);
        });
        $resolvedToday = ($item['status'] ?? null) === 'resolved'
            && collect($item['evidence'] ?? [])->contains(function (array $evidence) use ($start, $cutoff): bool {
                if (($evidence['transition'] ?? null) !== 'resolved' || blank($evidence['occurred_at'] ?? null)) {
                    return false;
                }

                return Carbon::parse($evidence['occurred_at'])->setTimezone($start->getTimezone())->betweenIncluded($start, $cutoff);
            });

        try {
            $human = $this->shouldInclude($item)
                ? $this->humanComposer->compose($item)
                : ['include' => false, 'decision' => 'omit', 'summary' => null, 'follow_up' => null];
        } catch (Throwable $exception) {
            if (app()->bound('log')) {
                app('log')->warning('Evening editorial composition failed; candidate omitted.', [
                    'event_key' => $item['event_key'] ?? null,
                    'exception' => $exception::class,
                ]);
            }

            $human = ['include' => false, 'decision' => 'technical_failure', 'summary' => null, 'follow_up' => null];
        }

        $summary = filled($human['summary'] ?? null) ? (string) $human['summary'] : null;
        $followUp = filled($human['follow_up'] ?? null) ? (string) $human['follow_up'] : null;
        $isPositive = in_array('positive_contribution', $item['types'] ?? [], true)
            && collect($item['types'] ?? [])->diff(['positive_contribution'])->isEmpty();
        $activeStatus = in_array($item['status'] ?? null, ['open', 'reopened'], true);
        $completed = ($human['completed'] ?? false) === true || $resolvedToday;
        $state = 'omit';
        $needsAttention = false;
        $nextAction = null;
        $renderOutcome = [];
        $resolution = null;

        if (($human['include'] ?? false) === true && $summary !== null) {
            if ($completed && $relevantToday) {
                $state = 'completed';

                if ($resolvedToday) {
                    $renderOutcome[] = 'resolved';
                    $resolution = $human['resolution'] ?? $this->resolutionSummary($item, $summary);
                } elseif (($human['show_in_day'] ?? true) === true) {
                    $renderOutcome[] = 'day';
                }
            } elseif ($isPositive && $relevantToday) {
                $state = 'informational';
                $renderOutcome[] = 'positive';
            } elseif ($activeStatus && ($relevantToday || ($item['carry_over'] ?? false))
                && ($item['attention_eligible'] ?? false) === true && $followUp !== null) {
                $state = 'active';
                $needsAttention = true;
                $nextAction = $followUp;
                $renderOutcome[] = 'attention';

                if ($relevantToday && ($human['show_in_day'] ?? true) === true) {
                    $renderOutcome[] = 'day';
                }
            } elseif ($relevantToday && ($item['status'] ?? null) !== 'resolved'
                && ($human['show_in_day'] ?? true) === true) {
                $state = 'informational';
                $renderOutcome[] = 'day';
            }
        }

        return [
            ...$item,
            'editorial' => [
                'relevant_today' => $relevantToday,
                'state' => $state,
                'needs_attention' => $needsAttention,
                'next_action' => $nextAction,
                'render_outcome' => $renderOutcome === [] ? ['omit'] : $renderOutcome,
                'summary' => $summary,
                'resolution' => $resolution,
            ],
        ];
    }

    /** @param Collection<int, array<string, mixed>> $items
     *  @return array<int, array{key: string, label: string, items: array<int, array<string, mixed>>}>
     */
    private function editorialSections(Collection $items): array
    {
        $labels = [
            'day' => 'За день',
            'resolved' => 'Решено сегодня',
            'positive' => 'Хорошая работа',
            'attention' => 'Требует внимания',
            'actions' => 'Осталось сделать',
        ];
        $sections = [];
        foreach ($labels as $key => $label) {
            $sections[$key] = ['key' => $key, 'label' => $label, 'items' => []];
        }

        foreach ($items as $item) {
            $editorial = $item['editorial'];

            foreach (['day', 'resolved', 'positive', 'attention'] as $key) {
                if (! in_array($key, $editorial['render_outcome'], true)) {
                    continue;
                }

                $summary = $key === 'resolved' ? $editorial['resolution'] : $editorial['summary'];
                if (filled($summary)) {
                    $sections[$key]['items'][] = $this->editorialLine($item, (string) $summary, $key);
                }
            }

            if ($editorial['needs_attention'] && filled($editorial['next_action'])) {
                $sections['actions']['items'][] = $this->editorialLine($item, (string) $editorial['next_action'], 'actions');
            }
        }

        foreach (['day', 'resolved', 'positive', 'attention', 'actions'] as $key) {
            $sections[$key]['items'] = collect($sections[$key]['items'])
                ->unique(fn (array $item): string => $key === 'actions'
                    ? sha1(mb_strtolower(($item['context_label'] ?? '').'|'.$item['summary']))
                    : (string) $item['event_key'])
                ->take($key === 'actions' ? 6 : 7)
                ->values()
                ->all();
        }

        return collect($sections)->filter(fn (array $section): bool => $section['items'] !== [])->values()->all();
    }

    /** @return array<string, mixed> */
    private function editorialLine(array $item, string $summary, string $section): array
    {
        $support = $section === 'actions' ? null : $this->primarySupportingEvidence($item, $summary, $section);

        return [
            'event_key' => $item['event_key'],
            'context_label' => $item['context_label'] ?? null,
            'summary' => $summary,
            'evidence' => $item['evidence'] ?? [],
            'author_name' => $support['author_name'] ?? null,
            'quote' => $support['quote'] ?? null,
        ];
    }

    /** @return array{author_name: ?string, quote: string}|null */
    private function primarySupportingEvidence(array $item, string $summary, string $section): ?array
    {
        $summaryTerms = $this->evidenceTerms($summary)
            ->unique()
            ->values();
        $preferredRoles = match ($section) {
            'resolved' => ['resolution'],
            'positive' => ['positive'],
            default => ['report', 'recurrence', 'question'],
        };
        $candidates = collect($item['_citation_candidates'] ?? [])
            ->map(function (array $candidate) use ($summaryTerms, $preferredRoles, $section): array {
                $quote = $this->cleanEvidenceQuote((string) ($candidate['text'] ?? ''));
                $overlap = $summaryTerms->intersect($this->evidenceTerms($quote))->count();
                $preferred = in_array($candidate['role'] ?? null, $preferredRoles, true)
                    || ($section === 'resolved' && ($candidate['transition'] ?? null) === 'resolved');

                return [
                    ...$candidate,
                    'quote' => $quote,
                    'overlap' => $overlap,
                    'preferred' => $preferred,
                    'score' => ($overlap * 10) + (($candidate['is_root'] ?? false) ? 2 : 0) + ($preferred ? 1 : 0),
                ];
            })
            // Role and root status may break ties, but never make unrelated text eligible.
            ->filter(fn (array $candidate): bool => $candidate['quote'] !== '' && ($candidate['overlap'] ?? 0) > 0)
            ->all();

        usort($candidates, fn (array $left, array $right): int =>
            ($right['score'] <=> $left['score'])
            ?: (($right['is_root'] ?? false) <=> ($left['is_root'] ?? false))
            ?: strcmp((string) ($left['occurred_at'] ?? ''), (string) ($right['occurred_at'] ?? ''))
            ?: (($left['message_id'] ?? 0) <=> ($right['message_id'] ?? 0))
        );

        if ($candidates === []) {
            return null;
        }

        $candidate = $candidates[0];

        return [
            'author_name' => $candidate['author_name'] ?? null,
            'quote' => mb_strimwidth($candidate['quote'], 0, 140, '…'),
        ];
    }

    private function cleanEvidenceQuote(string $text): string
    {
        $text = preg_replace('/\s+/u', ' ', strip_tags($text)) ?: '';
        $text = preg_replace('/(?<!\S)@[\pL\pN_]+/u', '', $text) ?: $text;

        return trim(preg_replace('/\s+/u', ' ', $text) ?: $text);
    }

    /** @return Collection<int, string> */
    private function evidenceTerms(string $text): Collection
    {
        $genericPrefixes = ['обнар', 'проб', 'ситу', 'сегод', 'сотр', 'сообщ', 'треб', 'необх', 'пример', 'сдел', 'пров', 'устра', 'уточн', 'слом'];
        $suffixes = ['иями', 'ями', 'ами', 'ого', 'ему', 'ыми', 'ими', 'ее', 'ие', 'ые', 'ое', 'ей', 'ой', 'ий', 'ый', 'ая', 'яя', 'ую', 'юю', 'ам', 'ям', 'ах', 'ях', 'ов', 'ев', 'ом', 'ем', 'а', 'я', 'ы', 'и', 'е', 'у', 'ю', 'ь'];

        return collect(preg_split('/[^\pL\pN]+/u', mb_strtolower($text)) ?: [])
            ->filter(fn (string $term): bool => mb_strlen($term) >= 4 || preg_match('/^\d{3,}$/u', $term) === 1)
            ->map(function (string $term) use ($suffixes): string {
                foreach ($suffixes as $suffix) {
                    if (mb_strlen($term) - mb_strlen($suffix) >= 3 && str_ends_with($term, $suffix)) {
                        return mb_substr($term, 0, mb_strlen($term) - mb_strlen($suffix));
                    }
                }

                return $term;
            })
            ->reject(fn (string $term): bool => collect($genericPrefixes)->contains(
                fn (string $prefix): bool => str_starts_with($term, $prefix)
            ))
            ->unique()
            ->values();
    }

    private function resolutionSummary(array $item, string $summary): string
    {
        $types = collect($item['types'] ?? []);

        return match (true) {
            preg_match('/двер|доступ|консьерж/iu', (string) ($item['summary'] ?? '')) === 1 => 'Проблема с доступом решена.',
            $types->contains('quality_issue') => 'Замечание по качеству устранено.',
            $types->contains('unanswered_question') => 'Вопрос закрыт.',
            $types->contains('delay') => 'Ситуация с задержкой закрыта.',
            default => 'Ситуация решена: '.$summary,
        };
    }

    private function currentOpenPeriodStart(Collection $evidence, string $status): ?Carbon
    {
        if (! in_array($status, ['open', 'reopened'], true)) {
            return null;
        }

        return $evidence
            ->reverse()
            ->first(fn (TelegramOperationalEventEvidence $item): bool => in_array(
                $item->transition,
                ['created', 'reopened'],
                true,
            ))?->occurred_at?->copy();
    }

    private function openAgeDays(?Carbon $openSince, Carbon $cutoff): ?int
    {
        if ($openSince === null) {
            return null;
        }

        $timezone = config('app.timezone', 'Europe/Rome');
        $openedDay = $openSince->copy()->setTimezone($timezone)->startOfDay();
        $cutoffDay = $cutoff->copy()->setTimezone($timezone)->startOfDay();

        return $openedDay->diffInDays($cutoffDay) + 1;
    }

    private function shouldInclude(array $item): bool
    {
        if ($item['evidence'] === [] || $item['types'] === []) {
            return false;
        }

        $hasSummaryType = collect($item['types'])->intersect(self::SUMMARY_TYPES)->isNotEmpty();

        $isOpenQuestion = in_array($item['status'], ['open', 'reopened'], true)
            && collect($item['evidence'])->contains(fn (array $evidence): bool => ($evidence['role'] ?? null) === 'question');

        if ($item['status'] !== 'resolved' && ! $hasSummaryType && ! $isOpenQuestion) {
            return false;
        }

        if ($item['confidence'] === 'low' && ! $isOpenQuestion) {
            return false;
        }

        if ($item['status'] === 'resolved'
            && $this->isGenericResolution($item['summary'])
            && collect($item['types'])->diff(['resolution'])->isEmpty()) {
            return false;
        }

        if (collect($item['types'])->diff(['positive_contribution'])->isEmpty()
            && $this->isGenericPositiveSummary($item['summary'])) {
            return false;
        }

        return true;
    }

    private function weakestConfidence(Collection $evidence): string
    {
        $confidence = $evidence
            ->pluck('confidence')
            ->map(fn (mixed $value) => in_array($value, ['high', 'medium', 'low'], true) ? $value : 'low');

        if ($confidence->contains('low')) {
            return 'low';
        }

        return $confidence->contains('medium') ? 'medium' : 'high';
    }

    private function sections(array $items): array
    {
        $collection = collect($items);
        $resolved = $collection->where('status', 'resolved')->take(7)->values();
        $remaining = $collection->whereIn('status', ['open', 'reopened'])->values();
        $qualityMatches = $remaining
            ->filter(fn (array $item) => in_array('quality_issue', $item['types'], true));
        $quality = $qualityMatches->take(7)->values();
        $remaining = $this->withoutEvents($remaining, $qualityMatches);
        $riskMatches = $remaining
            ->filter(fn (array $item) => collect($item['types'])->intersect(['risk', 'delay'])->isNotEmpty());
        $risksDelays = $riskMatches->take(7)->values();
        $remaining = $this->withoutEvents($remaining, $riskMatches);
        $positiveMatches = $remaining
            ->filter(fn (array $item) => in_array('positive_contribution', $item['types'], true)
                && collect($item['types'])->diff(['positive_contribution'])->isEmpty());
        $positive = $positiveMatches->take(7)->values();
        $remaining = $this->withoutEvents($remaining, $positiveMatches);
        $attention = $remaining
            ->filter(fn (array $item) => collect($item['types'])->intersect(self::ATTENTION_TYPES)->isNotEmpty())
            ->take(7)
            ->values();

        return collect([
            ['key' => 'attention', 'label' => 'Требует внимания', 'items' => $attention->all()],
            ['key' => 'quality', 'label' => 'Качество', 'items' => $quality->all()],
            ['key' => 'risks_delays', 'label' => 'Риски и задержки', 'items' => $risksDelays->all()],
            ['key' => 'resolved', 'label' => 'Решено', 'items' => $resolved->all()],
            ['key' => 'positive', 'label' => 'Положительный вклад', 'items' => $positive->all()],
        ])->filter(fn (array $section) => $section['items'] !== [])->values()->all();
    }

    private function withoutEvents(Collection $source, Collection $selected): Collection
    {
        $keys = $selected->pluck('event_key');

        return $source
            ->reject(fn (array $item) => $keys->contains($item['event_key']))
            ->values();
    }

    private function isGenericResolution(string $summary): bool
    {
        $normalized = mb_strtolower(trim($summary, " \t\n\r\0\x0B.!?,"));

        return in_array($normalized, [
            'готово', 'сделано', 'решено', 'исправлено', 'всё готово', 'все готово',
            'done', 'fixed', 'resolved', 'ok', 'ок',
        ], true);
    }

    private function isGenericPositiveSummary(string $summary): bool
    {
        $normalized = mb_strtolower(trim($summary));
        $normalized = preg_replace('/^[\p{P}\p{S}\s]+|[\p{P}\p{S}\s]+$/u', '', $normalized) ?: '';

        return preg_match('/^(?:спасибо(?:\s+(?:большое|огромное|всем|за\s+помощь|и\s+хорошего\s+дня))?|благодарю|молодец|супер|отлично|хорошего дня)$/u', $normalized) === 1;
    }

    private function contextLabel(?string $value): ?string
    {
        $value = $this->compact((string) $value, 80);

        if ($value === '' || preg_match('/^(?:тема\s*#?\d+|operations|общая тема)$/iu', $value)) {
            return null;
        }

        return $value;
    }

    private function eventContextLabel(TelegramOperationalEvent $event): ?string
    {
        if (filled($event->apartment?->name)) {
            return $this->contextLabel($event->apartment->name);
        }

        if ($event->topic === null
            || $this->topicPresenter->isServiceTopic($event->topic)
            || ! $this->topicPresenter->hasHumanTitle($event->topic)) {
            return null;
        }

        return $this->contextLabel($this->topicPresenter->title($event->topic));
    }

    private function sortItems(array &$items): void
    {
        usort($items, function (array $left, array $right): int {
            $type = $this->typePriority($left) <=> $this->typePriority($right);

            if ($type !== 0) {
                return $type;
            }

            $confidence = $this->confidencePriority($left['confidence'])
                <=> $this->confidencePriority($right['confidence']);

            if ($confidence !== 0) {
                return $confidence;
            }

            $activity = strcmp(
                (string) $right['latest_activity_at'],
                (string) $left['latest_activity_at'],
            );

            return $activity !== 0
                ? $activity
                : strcmp($left['event_key'], $right['event_key']);
        });
    }

    private function typePriority(array $item): int
    {
        return collect($item['types'])
            ->map(fn (string $type) => self::TYPE_PRIORITY[$type] ?? 99)
            ->min() ?? 99;
    }

    private function confidencePriority(string $confidence): int
    {
        return match ($confidence) {
            'high' => 0,
            'medium' => 1,
            default => 2,
        };
    }

    private function compact(string $value, int $width): string
    {
        $value = preg_replace('/\s+/u', ' ', strip_tags($value)) ?: '';

        return trim(mb_strimwidth($value, 0, $width, '…'));
    }
}
