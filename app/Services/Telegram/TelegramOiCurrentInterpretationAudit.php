<?php

namespace App\Services\Telegram;

use App\Models\TelegramMessage;
use App\Models\TelegramOperationalEvent;
use Carbon\Carbon;
use Illuminate\Console\Command;

class TelegramOiCurrentInterpretationAudit
{
    private const MESSAGE_LIMIT = 500;

    public function __construct(
        private readonly TelegramOperationalInterpreter $interpreter,
        private readonly TelegramDistrictRouteRegistry $districts,
        private readonly TelegramTopicPresenter $topics,
    ) {}

    public function build(Carbon $date, ?array $district, mixed $eventFilter = null, mixed $apartmentFilter = null): array
    {
        $allowed = $this->districts->operationalChatIds();
        $start = $date->copy()->startOfDay();
        $end = $date->copy()->endOfDay();
        $messages = TelegramMessage::query()
            ->whereBetween('sent_at', [$start, $end])
            ->whereHas('chat', fn ($query) => $query->whereIn('telegram_chat_id', $allowed)->where('type', '!=', 'private'))
            ->when($district !== null, fn ($query) => $query->whereHas('chat', fn ($chat) => $chat->where('telegram_chat_id', $district['chat_id'])))
            ->when(filled($eventFilter), fn ($query) => $query->where(function ($linked) use ($eventFilter): void {
                $match = function ($event) use ($eventFilter): void {
                    $event->where('event_key', (string) $eventFilter);
                    if (ctype_digit((string) $eventFilter)) {
                        $event->orWhere('id', (int) $eventFilter);
                    }
                };
                $linked->whereHas('operationalEvidence.event', $match)
                    ->orWhereIn('id', TelegramOperationalEvent::query()->select('root_message_id')->where($match));
            }))
            ->when(filled($apartmentFilter), fn ($query) => $query->where(fn ($context) => $context
                ->whereHas('topic', fn ($topic) => $topic->where('apartment_id', (int) $apartmentFilter))
                ->orWhereHas('operationalEvidence.event', fn ($event) => $event->where('apartment_id', (int) $apartmentFilter))))
            ->with(['chat', 'topic.chat', 'telegramUser', 'operationalObservations.evidence.event'])
            ->orderBy('sent_at')->orderBy('id')->limit(self::MESSAGE_LIMIT + 1)->get();
        $truncated = $messages->count() > self::MESSAGE_LIMIT;
        $roots = TelegramOperationalEvent::query()->whereIn('root_message_id', $messages->take(self::MESSAGE_LIMIT)->pluck('id'))->get()->groupBy('root_message_id');
        $comparisons = $messages->take(self::MESSAGE_LIMIT)->map(function (TelegramMessage $message) use ($roots): array {
            $text = trim((string) ($message->text ?: $message->caption ?: ''));
            $current = $this->interpreter->interpret($text);
            $historical = $message->operationalObservations->sortBy('id')->map(function ($observation): array {
                return [
                    'observation_id' => $observation->id,
                    'source_revision_hash' => $observation->source_revision_hash,
                    'evaluation_kind' => $observation->evaluation_kind,
                    'current_revision' => $observation->is_current_revision,
                    'state' => $observation->state,
                    'outcome' => $observation->outcome,
                    'reason_code' => $observation->reason_code,
                    'confidence' => $observation->confidence,
                    'evidence' => $observation->evidence->sortBy('id')->map(fn ($link): array => [
                        'evidence_id' => $link->id,
                        'current_revision' => $link->is_current_revision,
                        'role' => $link->role,
                        'transition' => $link->transition,
                        'status_before' => $link->status_before,
                        'status_after' => $link->status_after,
                        'confidence' => $link->confidence,
                        'occurred_at' => $link->occurred_at?->toIso8601String(),
                        // Event fields are the stored ledger snapshot, not a historical interpreter payload.
                        'event' => $link->event?->only(['id', 'event_key', 'primary_type', 'status', 'subject_key', 'summary']),
                    ])->values()->all(),
                ];
            })->values()->all();
            $labels = [];
            foreach ($historical as &$observation) {
                foreach ($observation['evidence'] as &$link) {
                    $link['comparison'] = ! $observation['current_revision'] || ! $link['current_revision'] || $link['event'] === null
                        ? 'uncomparable'
                        : $this->compare($observation, $link, $current);
                    $labels[] = $link['comparison'];
                }
                unset($link);
            }
            unset($observation);
            if ($labels === []) {
                $labels[] = $roots->has($message->id)
                    ? ($current['meaningful'] ? 'uncomparable' : 'historical_event_now_no_event')
                    : ($current['meaningful'] ? 'historical_no_event_now_event' : 'same_no_event');
                if (! $current['meaningful'] && ! $roots->has($message->id)) {
                    foreach ($historical as $observation) {
                        if ($observation['current_revision'] && $observation['reason_code'] !== null
                            && $observation['reason_code'] !== ($current['reason_code'] ?? null)) {
                            $labels = ['interpretation_changed'];
                        }
                    }
                }
            }

            return [
                'message_id' => $message->id,
                'telegram_message_id' => $message->message_id,
                'district' => $message->chat ? $this->topics->chatLabel($message->chat) : null,
                'topic' => $message->topic ? $this->topics->title($message->topic) : null,
                'timestamp' => $message->sent_at?->toIso8601String(),
                'edited_at' => $message->edited_at?->toIso8601String(),
                'author' => $message->telegramUser?->full_name ?: $message->telegramUser?->username,
                'text' => $text,
                'historical' => $historical,
                'historical_root_events' => $roots->get($message->id, collect())->map(fn ($event): array => $event->only(['id', 'event_key', 'primary_type', 'status', 'subject_key', 'summary']))->all(),
                'current' => ['outcome' => $current['meaningful'] ? 'event' : 'no_event', ...$current],
                'comparison' => array_values(array_unique($labels)),
                'changed' => array_unique($labels) === ['uncomparable']
                    ? null
                    : count(array_diff($labels, ['same_event', 'same_no_event', 'uncomparable'])) > 0,
            ];
        })->values();

        return [
            'date' => $date->toDateString(),
            'timezone' => config('app.timezone', 'Europe/Rome'),
            'district' => $district['key'] ?? null,
            'read_only' => true,
            'ledger_mutations' => 0,
            'telegram_actions' => 0,
            'messages_inspected' => $comparisons->count(),
            'limit' => self::MESSAGE_LIMIT,
            'truncated' => $truncated,
            'comparisons' => $comparisons->all(),
            'counts' => $comparisons->flatMap(fn (array $row) => $row['comparison'])->countBy()->all(),
            'changed_messages' => $comparisons->where('changed', true)->count(),
            'event_outcomes' => [
                'historical_event_current_event' => $comparisons->filter(fn (array $row): bool => $row['current']['meaningful'] && ($row['historical_root_events'] !== [] || collect($row['historical'])->contains(fn (array $observation): bool => $observation['evidence'] !== [])))->count(),
                'historical_event_current_no_event' => $comparisons->filter(fn (array $row): bool => in_array('historical_event_now_no_event', $row['comparison'], true))->count(),
                'historical_no_event_current_event' => $comparisons->filter(fn (array $row): bool => in_array('historical_no_event_now_event', $row['comparison'], true))->count(),
            ],
        ];
    }

    private function compare(array $observation, array $link, array $current): string
    {
        if (! $current['meaningful']) {
            return 'historical_event_now_no_event';
        }
        if ($link['event']['primary_type'] === null || $link['role'] === null) {
            return 'uncomparable';
        }
        foreach ([
            'primary_type' => [$link['event']['primary_type'], 'type_changed'],
            'role' => [$link['role'], 'role_changed'],
            'subject_key' => [$link['event']['subject_key'], 'subject_changed'],
            'confidence' => [$link['confidence'], 'confidence_changed'],
            'transition' => [$link['transition'], 'interpretation_changed'],
            'reason_code' => [$observation['reason_code'], 'interpretation_changed'],
        ] as $field => [$stored, $category]) {
            if ($stored !== null && $stored !== ($current[$field] ?? null)) {
                return $category;
            }
        }

        return 'same_event';
    }

    public function render(Command $command, array $audit): void
    {
        foreach ($audit['comparisons'] as $row) {
            $command->line('--------------------------------------------------');
            $command->line('MESSAGE '.$row['message_id']);
            foreach (['district', 'topic', 'timestamp', 'author', 'text'] as $field) {
                $command->line(strtoupper($field).': '.($row[$field] ?? '—'));
            }
            $command->line('HISTORICAL LEDGER (all revisions; event fields are stored snapshots)');
            $command->line(json_encode($row['historical'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $command->line('ROOT EVENTS: '.json_encode($row['historical_root_events'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $command->line('CURRENT INTERPRETER (message-level; status is not returned)');
            $command->line(json_encode($row['current'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $changed = $row['changed'] === null ? 'unavailable' : ($row['changed'] ? 'yes' : 'no');
            $command->line('COMPARISON: '.implode(', ', $row['comparison']).'; changed: '.$changed);
        }
        $command->line('SUMMARY');
        $command->line('Messages inspected: '.$audit['messages_inspected']);
        $command->line('Changed interpretation: '.$audit['changed_messages']);
        foreach ($audit['event_outcomes'] as $label => $count) {
            $command->line($label.': '.$count);
        }
        foreach ($audit['counts'] as $label => $count) {
            $command->line($label.': '.$count);
        }
        $command->line('Truncated: '.($audit['truncated'] ? 'yes' : 'no'));
        $command->line('Read-only: yes. Ledger mutations: 0. Telegram actions: 0.');
    }
}
