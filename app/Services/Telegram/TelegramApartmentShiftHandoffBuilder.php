<?php

namespace App\Services\Telegram;

use App\Models\Apartment;
use App\Models\TelegramTopic;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class TelegramApartmentShiftHandoffBuilder
{
    public function __construct(
        private readonly TelegramEveningIntelligenceBuilder $editorialBuilder,
        private readonly TelegramApartmentShiftHandoffFormatter $formatter,
    ) {}

    /** @return array{date: string, handoffs: array<int, array<string, mixed>>, skipped: array<int, array<string, mixed>>} */
    public function build(Carbon|string $date): array
    {
        $preview = $this->editorialBuilder->build($date);
        $events = collect($preview['events'] ?? [])->keyBy('event_key');
        $attention = collect($preview['editorial_sections'] ?? [])
            ->firstWhere('key', 'attention')['items'] ?? [];
        $attentionByKey = collect($attention)->keyBy('event_key');

        $activeEvents = $events
            ->filter(fn (array $event): bool => ($event['editorial']['needs_attention'] ?? false) === true);
        $unassigned = $activeEvents
            ->filter(fn (array $event): bool => ! is_numeric($event['apartment_id'] ?? null))
            ->map(fn (array $event): array => [
                'event_key' => $event['event_key'],
                'apartment_id' => null,
                'mapping_status' => 'missing_apartment',
            ])
            ->values()
            ->all();
        $items = $activeEvents
            ->map(function (array $event, string $eventKey) use ($attentionByKey): ?array {
                $line = $attentionByKey->get($eventKey);
                $apartmentId = $event['apartment_id'] ?? null;

                if ($line === null || ! is_numeric($apartmentId)) {
                    return null;
                }

                return [
                    'event_key' => $eventKey,
                    'apartment_id' => (int) $apartmentId,
                    'summary' => (string) ($line['summary'] ?? ''),
                    'author_name' => $line['author_name'] ?? null,
                    'quote' => $line['quote'] ?? null,
                    'next_action' => $event['editorial']['next_action'] ?? null,
                ];
            })
            ->filter(fn (?array $item): bool => $item !== null && filled($item['summary']))
            ->values();

        if ($items->isEmpty()) {
            return ['date' => (string) $preview['date'], 'handoffs' => [], 'skipped' => $unassigned];
        }

        // Resolve all apartment → topic mappings in one analytics query. Multiple
        // enabled topics for one apartment are ambiguous and are never guessed.
        $apartmentIds = $items->pluck('apartment_id')->unique();
        $apartmentNames = Apartment::query()->whereKey($apartmentIds)->get(['id', 'name'])->keyBy('id');
        $topicsByApartment = TelegramTopic::query()
            ->whereIn('apartment_id', $apartmentIds)
            ->where('is_enabled', true)
            ->whereHas('chat', fn ($query) => $query->where('is_enabled', true))
            ->with('chat:id,telegram_chat_id,title')
            ->orderBy('id')
            ->get()
            ->groupBy('apartment_id');

        $handoffs = [];
        foreach ($items->groupBy('apartment_id') as $apartmentId => $apartmentItems) {
            $topics = $topicsByApartment->get((string) $apartmentId, collect());
            $topic = $topics->count() === 1 ? $topics->first() : null;
            $mappingStatus = match (true) {
                $topics->isEmpty() => 'mapping_missing',
                $topics->count() > 1 => 'mapping_ambiguous',
                default => 'ready',
            };

            $handoffs[] = [
                'apartment_id' => (int) $apartmentId,
                'apartment_name' => $apartmentNames->get((int) $apartmentId)?->name ?? 'Apartment '.$apartmentId,
                'destination_chat_id' => $topic?->chat?->telegram_chat_id,
                'destination_thread_id' => $topic?->telegram_thread_id,
                'destination_topic_title' => $topic?->title ?: ($topic ? 'Тема #'.$topic->telegram_thread_id : null),
                'mapping_status' => $mappingStatus,
                'item_count' => $apartmentItems->count(),
                'message' => $mappingStatus === 'ready'
                    ? $this->formatter->format($apartmentItems->all())
                    : null,
                'items' => $apartmentItems->all(),
            ];
        }

        usort($handoffs, fn (array $left, array $right): int => strcmp($left['apartment_name'], $right['apartment_name']));

        return ['date' => (string) $preview['date'], 'handoffs' => $handoffs, 'skipped' => $unassigned];
    }
}
