<?php

namespace App\Services\Telegram;

use App\Models\TelegramOperationalEvent;
use App\Models\TelegramOperationalEventEvidence;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class TelegramLinenDefectReportBuilder
{
    public function __construct(
        private readonly TelegramEveningIntelligenceBuilder $evening,
        private readonly TelegramDistrictRouteRegistry $districts,
        private readonly TelegramTopicPresenter $topics,
    ) {}

    public function build(Carbon|string $date): array
    {
        $timezone = 'Europe/Rome';
        $window = $this->evening->reportingWindow($date);
        $day = Carbon::parse($window['start'])->setTimezone($timezone);
        $cutoff = Carbon::parse($window['evaluated_until'])->setTimezone($timezone);
        $startUtc = $day->copy()->setTimezone('UTC');
        $cutoffUtc = $cutoff->copy()->setTimezone('UTC');
        $routes = $this->districts->sourceRoutes();
        $districts = [];
        $cases = [];
        $seenSourceMessages = [];

        foreach ($routes as $route) {
            // Use the same source/observer completeness contract as Evening Intelligence.
            $quality = $this->evening->sourceDataQuality($day, $route, $cutoff);
            $districts[$route['key']] = [
                'label' => $route['label'],
                'source_status' => $quality['source_status'],
                'source_messages' => $quality['source_messages'],
                'observed_messages' => $quality['observed_messages'],
                'known_units' => 0,
            ];

            $events = TelegramOperationalEvent::query()
                ->whereIn('primary_type', ['quality_issue', 'problem', 'positive_contribution'])
                ->whereHas('chat', fn ($query) => $query->where('telegram_chat_id', (string) $route['chat_id']))
                ->whereHas('evidence', fn ($query) => $query
                    ->where('is_current_revision', true)
                    ->where('occurred_at', '>=', $startUtc)
                    ->where('occurred_at', '<', $cutoffUtc))
                ->with([
                    'chat', 'topic.chat', 'apartment',
                    'evidence' => fn ($query) => $query
                        ->where('is_current_revision', true)
                        ->where('occurred_at', '>=', $startUtc)
                        ->where('occurred_at', '<', $cutoffUtc)
                        ->with('observation.message')
                        ->orderBy('occurred_at')
                        ->orderBy('id'),
                ])->get();

            foreach ($events as $event) {
                // One OI event is one incident: include all linked defect sources, but never
                // add repeated quantities across separate source messages.
                $sourceMessages = $event->evidence->filter(function (TelegramOperationalEventEvidence $evidence): bool {
                    $message = $evidence->observation?->message;

                    return $message !== null
                        && in_array($evidence->role, ['report', 'resolution', 'recurrence', 'positive'], true)
                        && $evidence->confidence !== 'low'
                        && $this->isDefect((string) ($message->text ?: $message->caption));
                })->map(fn (TelegramOperationalEventEvidence $evidence) => $evidence->observation?->message)
                    ->filter()
                    ->unique('id')
                    ->reject(fn ($message): bool => isset($seenSourceMessages[$message->id]))
                    ->values();

                if ($sourceMessages->isEmpty()) {
                    continue;
                }
                foreach ($sourceMessages as $sourceMessage) {
                    $seenSourceMessages[$sourceMessage->id] = true;
                }

                $source = $sourceMessages->first();
                $text = html_entity_decode((string) ($source->text ?: $source->caption), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $details = $this->detailsForMessages($sourceMessages);
                $defects = $sourceMessages->map(fn ($message): string => $this->defectLabel(
                    html_entity_decode((string) ($message->text ?: $message->caption), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                ))->unique()->values();
                $knownDetailCount = collect($details)->filter(fn (array $detail): bool => $detail['quantity'] !== null)->count();
                $location = trim((string) $event->apartment?->name);
                if ($location === '' && $event->topic !== null && ! $this->topics->isServiceTopic($event->topic)
                    && $this->topics->hasHumanTitle($event->topic)) {
                    $location = $this->topics->title($event->topic);
                }
                $cases[] = [
                    'event_key' => $event->event_key,
                    'district' => $route['key'],
                    'district_label' => $route['label'],
                    'location' => $location !== '' && preg_match('/^\d+$/u', $location) !== 1 ? $location : null,
                    'details' => $details,
                    'source_urls' => $sourceMessages->map(fn ($message): string => $this->topics->messageUrl(
                        (string) $event->chat?->telegram_chat_id,
                        (string) $message->message_id,
                    ))->filter()->unique()->values()->all(),
                    'quantity_confidence' => $knownDetailCount === count($details)
                        ? 'confirmed'
                        : ($knownDetailCount > 0 ? 'partial' : 'unknown'),
                    'defect' => $defects->implode(', '),
                    'source_url' => $this->topics->messageUrl((string) $event->chat?->telegram_chat_id, (string) $source->message_id),
                ];
            }
        }

        $groups = collect($cases)->groupBy(fn (array $case): string => hash('sha256', implode('|', [
            $case['district'], mb_strtolower((string) $case['location']),
            $case['defect'], json_encode($case['details']),
        ])));
        foreach ($groups as $indices) {
            if ($indices->count() < 2) {
                continue;
            }
            foreach ($cases as &$case) {
                if ($indices->contains(fn (array $member): bool => $member['event_key'] === $case['event_key'])) {
                    $case['quantity_confidence'] = 'uncertain_duplicate';
                }
            }
            unset($case);
        }
        foreach ($cases as $case) {
            if ($case['quantity_confidence'] !== 'uncertain_duplicate') {
                $districts[$case['district']]['known_units'] += array_sum(array_filter(
                    array_column($case['details'], 'quantity'),
                    fn (?int $quantity): bool => $quantity !== null,
                ));
            }
        }

        $caseCollection = collect($cases);
        $itemDetailCount = $caseCollection->sum(fn (array $case): int => count($case['details']));
        $unknownQuantityDetails = $caseCollection->sum(fn (array $case): int => count(array_filter(
            $case['details'], fn (array $detail): bool => $detail['quantity'] === null,
        )));

        return [
            'date' => $day->toDateString(),
            'timezone' => $timezone,
            'reporting_window' => $window,
            'districts' => $districts,
            'cases' => $cases,
            'case_count' => count($cases),
            'item_detail_count' => $itemDetailCount,
            'unknown_quantity_details' => $unknownQuantityDetails,
            'known_units' => array_sum(array_column($districts, 'known_units')),
            'disputed_cases' => $caseCollection->where('quantity_confidence', 'uncertain_duplicate')->count(),
            'unknown_cases' => $caseCollection->filter(fn (array $case): bool => collect($case['details'])
                ->contains(fn (array $detail): bool => $detail['quantity'] === null))->count(),
            'incomplete' => $routes->isEmpty()
                || $routes->count() !== $this->districts->diagnostics()->count()
                || collect($districts)->contains(
                    fn (array $district): bool => $district['source_status'] !== 'available',
                ),
        ];
    }

    private function isDefect(string $text): bool
    {
        $text = mb_strtolower(html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if (preg_match('/(?:постира|отстира|стирк)/u', $text) === 1
            && preg_match('/(?:брак|дефект|дыр|порван|разорван|разрыв|изнош|износ|прот[её]р)/u', $text) !== 1) {
            return false;
        }
        if (preg_match('/(?:не\s+брак|брак\s+не\s+(?:делал|отмечал|оформлял)|без\s+(?:брака|дефектов))/', $text) === 1
            && preg_match('/(?:но|а)\s+.{0,70}(?:брак|дефект|пятн|дыр|порван|разорван|разрыв|изнош|износ|прот[её]р)/u', $text) !== 1) {
            return false;
        }

        $linen = '(?:простын\p{L}*|наволоч\p{L}*|полотен\p{L}*|пододеял\p{L}*|бель[еёя])';
        $defect = '(?:брак\p{L}*|дефект\p{L}*|пятн\p{L}*|дыр\p{L}*|порван\p{L}*|разорван\p{L}*|разрыв\p{L}*|поврежд\p{L}*|сильн\p{L}*\s+износ\p{L}*|изнош\p{L}*|прот[её]р\p{L}*)';

        return preg_match('/'.$linen.'.{0,70}'.$defect.'|'.$defect.'.{0,70}'.$linen.'/u', $text) === 1;
    }

    private function details(string $text): array
    {
        $types = [
            'простыня' => 'простын\p{L}*',
            'наволочка' => 'наволоч\p{L}*',
            'полотенце' => 'полотен\p{L}*',
            'пододеяльник' => 'пододеял\p{L}*',
        ];
        $details = [];
        foreach (preg_split('/[,.;]\s*(?:но\s+|а\s+)?|\s+но\s+/iu', $text) ?: [$text] as $clause) {
            if (preg_match('/(?:брак|дефект|пятн|дыр|порван|разорван|разрыв|поврежд|изнош|износ|прот[её]р)/iu', $clause) !== 1) {
                continue;
            }
            foreach ($types as $label => $pattern) {
                if (preg_match_all('/(?:(\d{1,3}|один|одна|одно|одну|два|две|три|четыре|пять)\s+)?('.$pattern.')/iu', $clause, $matches, PREG_SET_ORDER) < 1) {
                    continue;
                }
                foreach ($matches as $match) {
                    $quantity = match (mb_strtolower($match[1] ?? '')) {
                        'одна', 'одно', 'одну', 'один' => 1,
                        'два', 'две' => 2,
                        'три' => 3,
                        'четыре' => 4,
                        'пять' => 5,
                        '' => null,
                        default => (int) $match[1],
                    };
                    $details[] = ['item' => $label, 'quantity' => $quantity !== null && $quantity > 0 ? $quantity : null];
                }
            }
        }

        return $details !== [] ? $details : [['item' => 'бельё', 'quantity' => null]];
    }

    private function detailsForMessages(Collection $messages): array
    {
        $perMessage = $messages->map(function ($message): Collection {
            $text = html_entity_decode((string) ($message->text ?: $message->caption), ENT_QUOTES | ENT_HTML5, 'UTF-8');

            return collect($this->details($text))->groupBy('item')->map(function (Collection $items): ?int {
                if ($items->contains(fn (array $item): bool => $item['quantity'] === null)) {
                    return null;
                }

                return (int) $items->sum('quantity');
            });
        });
        $itemTypes = $perMessage->flatMap(fn (Collection $items) => $items->keys())->unique()->values();

        return $itemTypes->map(function (string $itemType) use ($perMessage): array {
            $quantities = $perMessage
                ->filter(fn (Collection $items): bool => $items->has($itemType))
                ->map(fn (Collection $items): ?int => $items->get($itemType))
                ->values();
            $quantity = $quantities->isNotEmpty()
                && $quantities->every(fn (?int $value): bool => $value !== null)
                && $quantities->unique()->count() === 1
                    ? $quantities->first()
                    : null;

            return ['item' => $itemType, 'quantity' => $quantity];
        })->all();
    }

    private function defectLabel(string $text): string
    {
        return match (true) {
            preg_match('/пятн/iu', $text) === 1 => 'пятна',
            preg_match('/дыр/iu', $text) === 1 => 'дыры',
            preg_match('/порван|разорван|разрыв/iu', $text) === 1 => 'разрывы',
            preg_match('/изнош|износ|прот[её]р/iu', $text) === 1 => 'сильный износ',
            default => 'брак/повреждение',
        };
    }
}
