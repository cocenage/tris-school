<?php

namespace App\Services\Telegram;

use App\Models\TelegramOperationalEvent;
use App\Models\TelegramOperationalEventEvidence;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class TelegramEveningIntelligenceBuilder
{
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

    public function build(Carbon|string $date): array
    {
        $timezone = config('app.timezone', 'Europe/Rome');
        $day = $date instanceof Carbon
            ? $date->copy()->setTimezone($timezone)->startOfDay()
            : Carbon::parse($date, $timezone)->startOfDay();
        $start = $day->copy()->startOfDay();
        $end = $day->copy()->endOfDay();

        $events = TelegramOperationalEvent::query()
            ->whereHas('evidence', fn ($query) => $query
                ->where('is_current_revision', true)
                ->whereBetween('occurred_at', [$start, $end]))
            ->with([
                'evidence' => fn ($query) => $query
                    ->where('is_current_revision', true)
                    ->where('occurred_at', '<=', $end)
                    ->with('observation.message')
                    ->orderBy('occurred_at')
                    ->orderBy('id'),
            ])
            ->get();

        $items = $events
            ->map(fn (TelegramOperationalEvent $event) => $this->project($event))
            ->filter()
            ->filter(fn (array $item) => $this->shouldInclude($item))
            ->values()
            ->all();
        $this->sortItems($items);

        $sections = $this->sections($items);
        $included = collect($sections)
            ->flatMap(fn (array $section) => $section['items'])
            ->pluck('event_key')
            ->unique()
            ->count();

        return [
            'date' => $day->toDateString(),
            'timezone' => $timezone,
            'sections' => $sections,
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

    private function project(TelegramOperationalEvent $event): ?array
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

        $types = collect([$event->primary_type])
            ->merge($evidence->map(function (TelegramOperationalEventEvidence $item): ?string {
                $reason = $item->observation?->reason_code;

                return $reason ? (self::REASON_TYPES[$reason] ?? null) : null;
            }))
            ->filter()
            ->unique()
            ->values()
            ->all();
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
        $reportCount = $evidence
            ->whereIn('role', ['report', 'recurrence'])
            ->count();

        return [
            'event_key' => $event->event_key,
            'summary' => $this->compact((string) $event->summary, 280),
            'types' => $types,
            'status' => $status,
            'confidence' => $confidence,
            'uncertainty' => $uncertainty !== '' ? $this->compact($uncertainty, 400) : null,
            'repeated' => $reportCount > 1 || $evidence->contains('transition', 'reopened'),
            'latest_activity_at' => $evidence->last()->occurred_at?->toIso8601String(),
            'evidence' => $references,
        ];
    }

    private function shouldInclude(array $item): bool
    {
        if ($item['evidence'] === [] || $item['types'] === []) {
            return false;
        }

        $hasSummaryType = collect($item['types'])->intersect(self::SUMMARY_TYPES)->isNotEmpty();

        if ($item['status'] !== 'resolved' && ! $hasSummaryType) {
            return false;
        }

        if ($item['confidence'] !== 'low') {
            return true;
        }

        return $item['status'] !== 'resolved'
            && collect($item['types'])->intersect(self::LOW_CONFIDENCE_TYPES)->isNotEmpty();
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
        $resolved = $collection->where('status', 'resolved')->values();
        $unresolved = $collection->whereIn('status', ['open', 'reopened'])->values();
        $positive = $unresolved
            ->filter(fn (array $item) => in_array('positive_contribution', $item['types'], true))
            ->values();
        $quality = $unresolved
            ->filter(fn (array $item) => in_array('quality_issue', $item['types'], true))
            ->values();
        $risksDelays = $unresolved
            ->filter(fn (array $item) => collect($item['types'])->intersect(['risk', 'delay'])->isNotEmpty())
            ->reject(fn (array $item) => in_array('quality_issue', $item['types'], true))
            ->values();
        $attention = $unresolved
            ->filter(fn (array $item) => collect($item['types'])->intersect(self::ATTENTION_TYPES)->isNotEmpty())
            ->reject(fn (array $item) => in_array('positive_contribution', $item['types'], true))
            ->reject(fn (array $item) => in_array('quality_issue', $item['types'], true))
            ->reject(fn (array $item) => collect($item['types'])->intersect(['risk', 'delay'])->isNotEmpty())
            ->values();
        $tomorrow = $unresolved
            ->filter(fn (array $item) => collect($item['types'])->intersect([
                'problem',
                'risk',
                'delay',
                'unanswered_question',
                'quality_issue',
                'request',
                'commitment',
            ])->isNotEmpty())
            ->values();

        return collect([
            ['key' => 'attention', 'label' => 'Требует внимания', 'items' => $attention->take(7)->all()],
            ['key' => 'resolved', 'label' => 'Решено за день', 'items' => $resolved->take(7)->all()],
            ['key' => 'quality', 'label' => 'Вопросы качества', 'items' => $quality->take(7)->all()],
            ['key' => 'risks_delays', 'label' => 'Риски и задержки', 'items' => $risksDelays->take(7)->all()],
            ['key' => 'positive', 'label' => 'Значимый положительный вклад', 'items' => $positive->take(7)->all()],
            ['key' => 'tomorrow', 'label' => 'Обратить внимание завтра', 'items' => $tomorrow->take(7)->all()],
        ])->filter(fn (array $section) => $section['items'] !== [])->values()->all();
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
