<?php

namespace App\Services\Telegram;

use App\Models\TelegramScheduledMessageDelivery;
use App\Models\TelegramScheduledMessageResponse;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;

class TelegramScheduledControlSummaryBuilder
{
    public function __construct(
        private TelegramScheduledControlStatistics $statistics,
        private ScheduledControlResponseInterpreter $interpreter,
    ) {}

    public function build(string $date, ?int $scheduledMessageId = null, ?string $controlType = null, ?string $chatId = null, ?string $throughDate = null): array
    {
        $start = $this->day($date);
        $end = $this->day($throughDate ?? $date)->addDay();
        if ($end->lte($start)) {
            throw new InvalidArgumentException('Invalid summary date range.');
        }
        $timezone = config('app.timezone', 'Europe/Rome');
        $query = TelegramScheduledMessageDelivery::query()
            ->with(['responses.telegramMessage.topic.apartment', 'scheduledMessage' => fn ($query) => $query->withTrashed()])
            ->whereIn('control_type', array_keys(ScheduledControlTypes::LABELS))
            ->where('scheduled_for', '>=', $start->setTimezone($timezone)->format('Y-m-d H:i:s'))
            ->where('scheduled_for', '<', $end->setTimezone($timezone)->format('Y-m-d H:i:s'))
            ->when($scheduledMessageId !== null, fn ($query) => $query->where('scheduled_message_id', $scheduledMessageId))
            ->when(filled($controlType), fn ($query) => $query->where('control_type', $controlType))
            ->when(filled($chatId), fn ($query) => $query->where('chat_id', $chatId))
            ->orderBy('scheduled_for')->orderBy('id');
        $deliveries = $query->get()->map(function ($delivery) use ($timezone): array {
            $stats = $this->statistics->delivery($delivery);
            $exceptions = $delivery->responses->whereIn('classification', ['problem', 'partial'])->take(3)->map(fn ($response) => [
                'classification' => $response->classification,
                'author_name' => $response->author_name,
                'text' => Str::limit((string) preg_replace('/\s+/u', ' ', $response->text), 180),
            ])->values()->all();

            $responses = $delivery->responses->map(function (TelegramScheduledMessageResponse $response) use ($delivery): array {
                $telegramMessage = $response->telegramMessage;
                $topic = $telegramMessage?->topic;
                $interpretation = $this->interpreter->interpret((string) $response->text, $delivery->control_type);
                $interpretation['apartment_id'] = $topic?->apartment_id;
                $interpretation['apartment'] = $topic?->apartment?->name;

                return $interpretation + [
                    'text' => Str::limit((string) preg_replace('/\s+/u', ' ', $response->text), 180),
                    'control_type' => $delivery->control_type,
                    'author_name' => $response->author_name,
                    'responder_key' => $response->user_id !== null ? 'user:'.$response->user_id
                        : ($response->telegram_user_id !== null ? 'telegram:'.$response->telegram_user_id : null),
                    'responded_at' => $response->responded_at?->toIso8601String(),
                    'source_message_id' => $response->telegram_message_id,
                    'reply_to_message_id' => $response->reply_to_message_id,
                ];
            })->values()->all();

            if ($responses !== []) {
                $stats['classification_counts'] = array_fill_keys(['confirmed', 'problem', 'partial', 'unclear'], 0);
                foreach ($responses as $response) {
                    $category = match ($response['status']) {
                        'ok' => 'confirmed',
                        'problem' => 'problem',
                        'partial' => 'partial',
                        default => 'unclear',
                    };
                    $stats['classification_counts'][$category]++;
                }
                $stats['result'] = collect(['problem', 'partial', 'confirmed', 'unclear'])
                    ->first(fn (string $category): bool => $stats['classification_counts'][$category] > 0);
            }

            return $stats + [
                'delivery_id' => $delivery->id, 'scheduled_message_id' => $delivery->scheduled_message_id,
                'control_type' => $delivery->control_type, 'name' => $delivery->scheduledMessage?->name ?? 'Удалённое сообщение',
                'scheduled_for' => CarbonImmutable::parse($delivery->getRawOriginal('scheduled_for'), $timezone)->setTimezone('Europe/Rome')->toIso8601String(),
                'sent_at' => $delivery->sent_at ? CarbonImmutable::parse($delivery->getRawOriginal('sent_at'), $timezone)->setTimezone('Europe/Rome')->toIso8601String() : null,
                'delivery_status' => $delivery->status, 'exceptions' => $exceptions, 'interpreted_responses' => $responses,
            ];
        })->all();
        $totals = $this->aggregate($deliveries);
        $groups = collect($deliveries)->groupBy('scheduled_message_id')->map(fn ($group) => $this->aggregate($group->all()))->all();

        $controls = collect($deliveries)->groupBy('control_type')->map(function ($rows, string $type): array {
            $responses = collect($rows)->flatMap(fn (array $row) => $row['interpreted_responses']);
            $statuses = $responses->pluck('status')->countBy();
            $label = ScheduledControlTypes::LABELS[$type] ?? $type;
            $districtResults = $this->mergeDistrictResults($responses);
            $status = $districtResults !== []
                ? $this->districtOverallStatus($districtResults)
                : ($statuses->get('problem', 0) + $statuses->get('partial', 0) > 0 ? 'problem'
                    : ($statuses->get('unknown', 0) > 0 ? 'unknown' : ($statuses->get('ok', 0) > 0 ? 'ok' : 'no_responses')));

            return [
                'control_type' => $type,
                'label' => $label,
                'count' => $rows->count(),
                'status' => $status,
                'district_results' => $districtResults,
                'responses' => $responses->all(),
            ];
        })->values()->all();

        $districtResponseIds = collect($deliveries)
            ->flatMap(fn (array $delivery) => $delivery['interpreted_responses'])
            ->filter(fn (array $response): bool => ($response['districts'] ?? []) !== [])
            ->pluck('source_message_id')
            ->map(fn (mixed $id): string => (string) $id)
            ->all();

        return ['date' => $date, 'through_date' => $throughDate ?? $date, 'timezone' => 'Europe/Rome',
            'expected_responders' => null, 'no_response_available' => false, 'totals' => $totals,
            'by_scheduled_message' => $groups, 'controls' => $controls,
            'exceptions' => collect($this->mergeExceptions(collect($deliveries)->flatMap(fn (array $row) => $row['interpreted_responses'])->values()->all()))
                ->reject(fn (array $exception): bool => in_array((string) ($exception['source_message_id'] ?? ''), $districtResponseIds, true))
                ->filter(fn (array $exception): bool => isset($exception['latest_problem']))
                ->values()->all(),
            'deliveries' => $deliveries];
    }

    private function mergeDistrictResults($responses): array
    {
        $entries = $responses
            ->flatMap(fn (array $response): array => collect($response['districts'] ?? [])
                ->map(fn (array $district): array => $district + [
                    'responded_at' => $response['responded_at'],
                    'responder_key' => $response['responder_key'],
                    'source_message_id' => $response['source_message_id'],
                    'source_text' => $response['text'],
                ])->all())
            ->sortBy(fn (array $entry): string => (string) $entry['responded_at'])
            ->values();
        $districts = [];

        foreach ($entries as $entry) {
            $name = $entry['district'];
            if (! isset($districts[$name])) {
                $districts[$name] = $entry + [
                    'conflict' => false,
                    'history' => [$entry['status']],
                    'source_message_ids' => [(string) $entry['source_message_id']],
                ];

                continue;
            }

            $current = &$districts[$name];
            $current['source_message_ids'][] = (string) $entry['source_message_id'];
            $current['details'] = array_values(array_unique([...$current['details'], ...$entry['details']]));
            $current['history'][] = $entry['status'];
            if ($current['status'] !== $entry['status']) {
                $isLaterSameResponder = filled($entry['responder_key'])
                    && $entry['responder_key'] === $current['responder_key']
                    && (string) $entry['source_message_id'] !== (string) $current['source_message_id'];
                $isExplicitCorrection = preg_match('/уточн|исправ|поправ|ошибк|на\s+самом\s+деле/iu', $entry['source_text']) === 1;

                if ($isLaterSameResponder || $isExplicitCorrection) {
                    $current['status'] = $entry['status'];
                    $current['reason'] = $entry['reason'] ?? null;
                    $current['no_payment'] = $entry['no_payment'];
                    $current['conflict'] = false;
                } else {
                    $current['status'] = 'conflict';
                    $current['conflict'] = true;
                }
            }
            $current['responded_at'] = $entry['responded_at'];
            $current['responder_key'] = $entry['responder_key'];
            $current['source_message_id'] = $entry['source_message_id'];
            unset($current);
        }

        return array_values($districts);
    }

    private function districtOverallStatus(array $districts): string
    {
        if (collect($districts)->contains(fn (array $district): bool => $district['status'] === 'problem')) {
            return 'problem';
        }
        if (collect($districts)->contains(fn (array $district): bool => $district['status'] === 'conflict')) {
            return 'unknown';
        }

        $configured = collect(config('services.telegram.digest_districts', []))
            ->map(fn (mixed $district): string => mb_strtolower((string) (is_array($district) ? ($district['label'] ?? '') : '')))
            ->filter()->unique()->values();
        $covered = collect($districts)->pluck('district')->map(fn (string $district): string => mb_strtolower($district))->unique();

        if ($configured->isNotEmpty() && $configured->diff($covered)->isNotEmpty()) {
            return 'partial';
        }

        if (collect($districts)->contains(fn (array $district): bool => $district['status'] === 'unknown')) {
            return 'unknown';
        }

        return 'ok';
    }

    private function mergeExceptions(array $responses): array
    {
        $groups = [];
        usort($responses, fn (array $left, array $right): int => strcmp((string) $left['responded_at'], (string) $right['responded_at']));
        foreach ($responses as $response) {
            $family = match ($response['control_type']) {
                'first_cleanings_started', 'first_cleanings_finishing' => 'first_cleaning',
                'second_cleanings_finishing' => 'second_cleaning',
                'couriers_completed' => 'courier',
                'extra_payments_completed' => 'payment',
                'schedule_checked' => 'schedule',
                default => $response['control_type'],
            };
            $identity = $response['apartment_id'] !== null && $response['responder_key'] !== null && $response['reason'] !== null
                ? implode('|', [$response['apartment_id'], $response['responder_key'], $family, $response['reason']])
                : null;
            $baseIdentity = $response['apartment_id'] !== null && $response['responder_key'] !== null
                ? implode('|', [$response['apartment_id'], $response['responder_key'], $family])
                : null;
            $key = null;
            if ($response['status'] === 'ok' && $baseIdentity !== null && $family === 'first_cleaning') {
                $open = collect($groups)->filter(fn (array $group): bool => ($group['identity_base'] ?? null) === $baseIdentity
                    && isset($group['latest_problem']) && ! ($group['resolved_later'] ?? false))->keys();
                if ($open->count() === 1) {
                    $key = $open->first();
                }
            }
            $key ??= $identity ?? 'response:'.$response['source_message_id'];
            if (! isset($groups[$key])) {
                $groups[$key] = $response + ['history' => [], 'identity_base' => $baseIdentity];
            }
            $groups[$key]['history'][] = [
                'control_type' => $response['control_type'], 'status' => $response['status'],
                'responded_at' => $response['responded_at'], 'source_message_id' => $response['source_message_id'],
            ];
            if ($response['status'] === 'problem' || $response['status'] === 'partial') {
                $groups[$key]['latest_problem'] = $response;
            } elseif ($response['status'] === 'ok' && $response['control_type'] === 'first_cleanings_finishing') {
                $groups[$key]['resolved_later'] = true;
            }
        }

        return array_values($groups);
    }

    public function day(string $date): CarbonImmutable
    {
        try {
            $day = CarbonImmutable::createFromFormat('!Y-m-d', $date, 'Europe/Rome');
        } catch (\Throwable) {
            throw new InvalidArgumentException('Date must be YYYY-MM-DD.');
        }
        if (! $day || $day->format('Y-m-d') !== $date) {
            throw new InvalidArgumentException('Date must be YYYY-MM-DD.');
        }

        return $day;
    }

    private function aggregate(array $deliveries): array
    {
        $rows = collect($deliveries);
        $totals = [
            'controls' => count($deliveries), 'responded' => $rows->where('response_count', '>', 0)->count(),
            'no_response' => $rows->where('result', 'no_response')->count(),
            'pending' => $rows->where('result', 'pending')->where('delivery_status', 'sent')->count(),
            'not_delivered' => $rows->where('delivery_status', '!=', 'sent')->count(),
            'response_messages' => $rows->sum('response_count'),
            'median_response_latency_seconds' => $this->statistics->median($rows->pluck('latency_samples')->flatten()->all()),
        ];
        foreach (['confirmed', 'problem', 'partial', 'unclear'] as $category) {
            $totals[$category] = $rows->where('result', $category)->count();
        }

        return $totals;
    }
}
