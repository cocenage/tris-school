<?php

namespace App\Services\Telegram;

use App\Models\TelegramOperationalEvent;
use App\Models\TelegramOperationalEventEvidence;
use Carbon\Carbon;

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
        $routes = $this->districts->sourceRoutes();
        $districts = [];
        $cases = [];

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
                    ->where('occurred_at', '>=', $day)
                    ->where('occurred_at', '<', $cutoff))
                ->with([
                    'chat', 'topic.chat', 'apartment',
                    'evidence' => fn ($query) => $query
                        ->where('is_current_revision', true)
                        ->where('occurred_at', '>=', $day)
                        ->where('occurred_at', '<', $cutoff)
                        ->with('observation.message')
                        ->orderBy('occurred_at')
                        ->orderBy('id'),
                ])->get();

            foreach ($events as $event) {
                // One OI event is one incident: later replies or status changes do not add units.
                $source = $event->evidence->first(function (TelegramOperationalEventEvidence $evidence): bool {
                    $message = $evidence->observation?->message;

                    return $message !== null
                        && in_array($evidence->role, ['report', 'resolution', 'recurrence', 'positive'], true)
                        && $evidence->confidence !== 'low'
                        && $this->isDefect((string) ($message->text ?: $message->caption));
                })?->observation?->message;

                if ($source === null) {
                    continue;
                }

                $text = html_entity_decode((string) ($source->text ?: $source->caption), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $details = $this->details($text);
                $known = array_sum(array_column($details, 'quantity'));
                $districts[$route['key']]['known_units'] += $known;
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
                    'defect' => $this->defectLabel($text),
                    'source_url' => $this->topics->messageUrl((string) $event->chat?->telegram_chat_id, (string) $source->message_id),
                ];
            }
        }

        return [
            'date' => $day->toDateString(),
            'timezone' => $timezone,
            'reporting_window' => $window,
            'districts' => $districts,
            'cases' => $cases,
            'known_units' => array_sum(array_column($districts, 'known_units')),
            'unknown_cases' => collect($cases)->sum(fn (array $case): int => count(array_filter(
                $case['details'], fn (array $detail): bool => $detail['quantity'] === null,
            ))),
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
        foreach ($types as $label => $pattern) {
            if (preg_match('/(?:(\d{1,3}|один|одна|одно|одну|два|две|три|четыре|пять)\s+)?('.$pattern.')/iu', $text, $match) !== 1) {
                continue;
            }
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

        return $details !== [] ? $details : [['item' => 'бельё', 'quantity' => null]];
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
