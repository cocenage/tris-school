<?php

namespace App\Services\Telegram;

use App\Models\TelegramScheduledMessage;
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
        private TelegramScheduledControlResponseService $responseService,
    ) {}

    public function build(string $date, ?int $scheduledMessageId = null, ?string $controlType = null, ?string $chatId = null, ?string $throughDate = null): array
    {
        $start = $this->day($date);
        $end = $this->day($throughDate ?? $date)->addDay();
        if ($end->lte($start)) {
            throw new InvalidArgumentException('Invalid summary date range.');
        }
        $timezone = config('app.timezone', 'Europe/Rome');
        $cutoff = $this->day($throughDate ?? $date)
            ->setTimeFromTimeString((string) config('services.telegram.scheduled_summary_cutoff', '22:00'));
        $query = TelegramScheduledMessageDelivery::query()
            ->with(['responses.telegramMessage.topic.apartment', 'followups', 'scheduledMessage' => fn ($query) => $query->withTrashed()])
            ->whereNotNull('control_type')->where('control_type', '!=', '')->where('control_type', '!=', 'control_question')
            ->where('scheduled_for', '>=', $start->setTimezone($timezone)->format('Y-m-d H:i:s'))
            ->where('scheduled_for', '<', $end->setTimezone($timezone)->format('Y-m-d H:i:s'))
            ->when($scheduledMessageId !== null, fn ($query) => $query->where('scheduled_message_id', $scheduledMessageId))
            ->when(filled($controlType), fn ($query) => $query->where('control_type', $controlType))
            ->when(filled($chatId), fn ($query) => $query->where('chat_id', $chatId))
            ->orderBy('scheduled_for')->orderBy('id');
        $deliveries = $query->get()->map(function ($delivery) use ($timezone, $cutoff): array {
            $sentAt = $delivery->sent_at
                ? CarbonImmutable::parse($delivery->getRawOriginal('sent_at'), $timezone)
                : null;
            $historyDelivery = clone $delivery;
            $historyResponses = $delivery->responses->map(function (TelegramScheduledMessageResponse $response) use ($cutoff, $sentAt, $delivery): ?TelegramScheduledMessageResponse {
                if ($delivery->status !== 'sent' || $sentAt === null || $response->responded_at === null) {
                    return null;
                }
                if ($response->responded_at->lessThanOrEqualTo($cutoff)) {
                    return $response;
                }
                $versions = collect($response->response_history ?? [])
                    ->filter(fn (array $version): bool => isset($version['responded_at'])
                        && CarbonImmutable::parse($version['responded_at'])->lessThanOrEqualTo($cutoff))
                    ->values();
                if ($versions->isEmpty()) {
                    return null;
                }
                $version = $versions->pop();
                $copy = clone $response;
                $at = CarbonImmutable::parse($version['responded_at']);
                $copy->text = $version['text'] ?? '';
                $copy->classification = $version['classification'] ?? 'unclear';
                $copy->responded_at = $at->setTimezone(config('app.timezone', 'Europe/Rome'));
                $copy->responded_at_utc = $at->setTimezone('UTC')->format('Y-m-d H:i:s');
                $copy->response_kind = $copy->classification === 'unclear' ? 'comment' : 'text_answer';
                $copy->response_history = $versions->all();

                return $copy;
            })->filter()->values();
            $historyDelivery->setRelation('responses', $historyResponses);
            $delivery->setRelation('responses', $delivery->responses->filter(fn (TelegramScheduledMessageResponse $response): bool => $delivery->status === 'sent' && $sentAt !== null && $response->responded_at !== null
                && $response->responded_at->greaterThanOrEqualTo($sentAt)
                && $response->responded_at->lessThanOrEqualTo($cutoff)
            )->values());
            $stats = $this->statistics->delivery($delivery, $cutoff);
            $displayResponses = $delivery->responses;
            if ($delivery->bot_type === 'work') {
                $latestAnswerIds = $displayResponses->filter(fn (TelegramScheduledMessageResponse $response): bool => in_array($response->response_kind, ['button', 'text_answer'], true) && $response->district_key !== null)
                    ->groupBy(fn (TelegramScheduledMessageResponse $response): string => $response->district_key.'|'.$response->telegram_user_id)
                    ->map(fn ($answers) => $answers->sortBy('id')->last()->id)->values()->all();
                $displayResponses = $displayResponses->filter(fn (TelegramScheduledMessageResponse $response): bool => ! in_array($response->response_kind, ['button', 'text_answer'], true)
                    || $response->district_key === null || in_array($response->id, $latestAnswerIds, true));
            }
            $exceptions = $displayResponses->whereIn('classification', ['problem', 'partial'])->take(3)->map(fn ($response) => [
                'classification' => $response->classification,
                'author_name' => $response->author_name,
                'text' => Str::limit((string) preg_replace('/\s+/u', ' ', $response->text), 180),
            ])->values()->all();

            $responses = $displayResponses->map(function (TelegramScheduledMessageResponse $response) use ($delivery): array {
                $telegramMessage = $response->telegramMessage;
                $topic = $telegramMessage?->topic;
                $interpretation = $this->interpreter->interpret((string) $response->text, $delivery->control_type);
                if ($delivery->bot_type === 'work' && isset(ScheduledControlTypes::DISTRICTS[$response->district_key])) {
                    $status = match ($response->response_kind === 'comment' ? 'unclear' : $response->classification) {
                        'confirmed' => 'ok', 'problem' => 'problem', 'partial' => 'partial', default => 'unknown',
                    };
                    $interpretation['status'] = $status;
                    $interpretation['issue'] = in_array($status, ['problem', 'partial'], true) ? trim((string) $response->text) : null;
                    $interpretation['districts'] = $response->response_kind === 'comment' ? [] : [[
                        'district' => ScheduledControlTypes::DISTRICTS[$response->district_key],
                        'status' => $status, 'reason' => $interpretation['reason'],
                        'details' => $response->response_kind === 'button' ? [] : [Str::limit((string) $response->text, 180)],
                        'no_payment' => false,
                    ]];
                }
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
                'group_key' => $delivery->bot_type === 'work' && isset(ScheduledControlTypes::LABELS[$delivery->control_type])
                    ? 'delivery:'.$delivery->id
                    : hash('sha256', implode('|', [$delivery->control_type, $delivery->chat_id, $delivery->message_thread_id,
                        CarbonImmutable::parse($delivery->getRawOriginal('scheduled_for'), $timezone)->toDateString()])),
                'control_type' => $delivery->control_type, 'name' => $delivery->scheduledMessage?->name ?? 'Удалённое сообщение',
                'scheduled_for' => CarbonImmutable::parse($delivery->getRawOriginal('scheduled_for'), $timezone)->setTimezone('Europe/Rome')->toIso8601String(),
                'sent_at' => $delivery->sent_at ? CarbonImmutable::parse($delivery->getRawOriginal('sent_at'), $timezone)->setTimezone('Europe/Rome')->toIso8601String() : null,
                'delivery_status' => $delivery->status, 'eligible_for_statistics' => $delivery->status === 'sent' && $sentAt !== null && $sentAt->lessThanOrEqualTo($cutoff),
                'district_history' => $delivery->bot_type === 'work' && $delivery->status === 'sent'
                    ? $this->districtHistory($historyDelivery, $cutoff) : [],
                'source_url' => str_starts_with((string) $delivery->chat_id, '-100') && $delivery->telegram_message_id
                    ? 'https://t.me/c/'.substr((string) $delivery->chat_id, 4).'/'.$delivery->telegram_message_id : null,
                'exceptions' => $exceptions, 'interpreted_responses' => $responses,
            ];
        })->all();
        $missingDeliveries = $this->missingDeliveries($start, $end, $scheduledMessageId, $controlType, $chatId);
        $totals = $this->aggregate($deliveries, count($missingDeliveries));
        $histories = collect($deliveries)->flatMap(fn (array $row) => $row['district_history']);
        $totals['delays_identified'] = $histories->whereNotNull('first_problem_at')->count();
        $totals['delays_resolved'] = $histories->whereNotNull('first_problem_at')->where('status', 'confirmed')->count();
        $totals['delays_open'] = $histories->whereNotNull('first_problem_at')->where('status', '!=', 'confirmed')->count();
        $totals['districts_without_answer'] = $histories->where('status', 'no_response')->count();
        $totals['district_conflicts'] = $histories->where('status', 'conflict')->count();
        $followupStatuses = $histories->flatMap(fn (array $history) => $history['followups']);
        $totals['followup_delivery_failures'] = $followupStatuses->where('status', 'failed')->count();
        $totals['followup_delivery_uncertain'] = $followupStatuses->whereIn('status', ['uncertain', 'sending'])->count();
        $groups = collect($deliveries)->groupBy('scheduled_message_id')->map(fn ($group) => $this->aggregate($group->all()))->all();

        $controls = collect($deliveries)->groupBy('group_key')->map(function ($rows): array {
            $type = (string) $rows->first()['control_type'];
            $representative = $rows->firstWhere('delivery_status', 'sent') ?? $rows->first();
            $responses = collect($rows)->flatMap(fn (array $row) => $row['interpreted_responses']);
            $statuses = $responses->pluck('status')->countBy();
            $label = ScheduledControlTypes::LABELS[$type] ?? $rows->first()['name'];
            $districtResults = $this->mergeDistrictResults($responses);
            $status = $districtResults !== []
                ? $this->districtOverallStatus($districtResults)
                : ($statuses->get('problem', 0) + $statuses->get('partial', 0) > 0 ? 'problem'
                    : ($statuses->get('unknown', 0) > 0 ? 'unknown' : ($statuses->get('ok', 0) > 0 ? 'ok' : 'no_responses')));

            return [
                'control_type' => $type,
                'label' => $label,
                'count' => $rows->count(),
                'statistics' => $this->aggregate($rows->all()),
                'status' => $status,
                'district_results' => $districtResults,
                'district_history' => $representative['district_history'],
                'delivery_status' => $representative['delivery_status'],
                'additional_delivery_statuses' => $rows->filter(fn (array $row): bool => $row['delivery_id'] !== $representative['delivery_id']
                    && $row['delivery_status'] !== 'sent')->pluck('delivery_status')->values()->all(),
                'source_url' => $representative['source_url'],
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
            'has_district_history' => $histories->isNotEmpty(),
            'expected_responders' => null, 'no_response_available' => false, 'totals' => $totals,
            'by_scheduled_message' => $groups, 'controls' => $controls,
            'exceptions' => collect($this->mergeExceptions(collect($deliveries)->flatMap(fn (array $row) => $row['interpreted_responses'])->values()->all()))
                ->reject(fn (array $exception): bool => in_array((string) ($exception['source_message_id'] ?? ''), $districtResponseIds, true))
                ->filter(fn (array $exception): bool => isset($exception['latest_problem']))
                ->values()->all(),
            'deliveries' => $deliveries, 'missing_deliveries' => $missingDeliveries];
    }

    private function districtHistory(TelegramScheduledMessageDelivery $delivery, CarbonImmutable $cutoff): array
    {
        $events = collect($this->responseService->districtAnswerEvents($delivery));
        $scheduledAt = CarbonImmutable::parse($delivery->getRawOriginal('scheduled_for'), 'Europe/Rome');
        $districts = [];

        foreach (ScheduledControlTypes::DISTRICTS as $key => $label) {
            $states = [];
            $firstProblem = null;
            $resolvedAt = null;
            $timeline = [];
            $timeUncertain = false;

            foreach ($events->where('district', $key) as $event) {
                $at = CarbonImmutable::parse($event['responded_at']);
                $sentAt = CarbonImmutable::parse($delivery->getRawOriginal('sent_at'), 'Europe/Rome');
                if ($at->lt($sentAt) || $at->gt($cutoff)) {
                    continue;
                }
                $previous = $states[$event['identity']] ?? null;
                if ($event['classification'] !== 'unclear' || ! in_array($previous, ['problem', 'partial'], true)) {
                    $states[$event['identity']] = $event['classification'];
                }
                if ($event['response']->getAttribute('responded_at_utc') === null
                    && app(TelegramScheduledControlFollowupService::class)->hasAmbiguousLocalTimestamp($event['response']->getRawOriginal('responded_at'))) {
                    $timeUncertain = true;
                }
                if (in_array($event['classification'], ['problem', 'partial'], true)) {
                    $firstProblem ??= $at;
                    $resolvedAt = null;
                } elseif ($firstProblem !== null && ! collect($states)->contains(fn (string $value): bool => $value !== 'confirmed')) {
                    $resolvedAt = $at;
                }
                $timeline[] = [
                    'status' => $event['classification'],
                    'responded_at' => $event['responded_at'],
                    'source_message_id' => $event['response']->telegram_message_id ?: $event['response']->reply_to_message_id,
                ];
            }

            $values = collect($states)->unique();
            $hasBad = $values->contains(fn (string $value): bool => in_array($value, ['problem', 'partial'], true));
            $status = match (true) {
                $values->isEmpty() => 'no_response',
                $hasBad && $values->contains('confirmed') => 'conflict',
                $values->contains('problem') => 'problem',
                $values->contains('partial') => 'partial',
                $values->contains('unclear') => 'no_response',
                default => 'confirmed',
            };
            if ($status !== 'confirmed') {
                $resolvedAt = null;
            }

            $districts[] = [
                'district' => $label, 'district_key' => $key, 'status' => $status,
                'scheduled_at' => $scheduledAt->toIso8601String(),
                'first_problem_at' => $firstProblem?->toIso8601String(),
                'resolved_at' => $resolvedAt?->toIso8601String(),
                'time_uncertain' => $timeUncertain,
                'delay_minutes' => $resolvedAt === null || $timeUncertain ? null : max(0, intdiv($resolvedAt->timestamp - $scheduledAt->timestamp, 60)),
                'timeline' => $timeline,
                'followups' => $delivery->followups->where('district_key', $key)
                    ->filter(fn ($followup): bool => $followup->created_at !== null && $followup->created_at->lessThanOrEqualTo($cutoff)
                        && ($followup->sent_at === null || $followup->sent_at->lessThanOrEqualTo($cutoff)))
                    ->map(fn ($followup): array => [
                        'kind' => $followup->kind, 'sequence' => $followup->sequence, 'status' => $followup->status,
                        'sent_at' => $followup->sent_at?->toIso8601String(),
                        'telegram_message_id' => $followup->telegram_message_id,
                    ])->values()->all(),
            ];
        }

        return $districts;
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

    private function missingDeliveries(CarbonImmutable $start, CarbonImmutable $end, ?int $scheduledMessageId, ?string $controlType, ?string $chatId): array
    {
        $timezone = config('app.timezone', 'Europe/Rome');
        $existing = TelegramScheduledMessageDelivery::query()
            ->where('scheduled_for', '>=', $start->setTimezone($timezone)->format('Y-m-d H:i:s'))
            ->where('scheduled_for', '<', $end->setTimezone($timezone)->format('Y-m-d H:i:s'))
            ->get(['scheduled_message_id', 'scheduled_for'])
            ->mapWithKeys(fn (TelegramScheduledMessageDelivery $delivery): array => [
                $delivery->scheduled_message_id.'|'.CarbonImmutable::parse($delivery->getRawOriginal('scheduled_for'), $timezone)->toDateString() => true,
            ]);
        $messages = TelegramScheduledMessage::query()->enabled()
            ->whereNotNull('control_type')->where('control_type', '!=', '')->where('control_type', '!=', 'control_question')
            ->where('created_at', '<', $end->format('Y-m-d H:i:s'))
            ->when($scheduledMessageId !== null, fn ($query) => $query->whereKey($scheduledMessageId))
            ->when(filled($controlType), fn ($query) => $query->where('control_type', $controlType))
            ->with('telegramChat')->get();
        $missing = [];
        $now = CarbonImmutable::now($timezone);

        for ($day = $start; $day->lt($end); $day = $day->addDay()) {
            $cutoff = $day->setTimeFromTimeString((string) config('services.telegram.scheduled_summary_cutoff', '22:00'));
            $dueThrough = $now->lt($cutoff) ? $now : $cutoff;

            foreach ($messages as $message) {
                $scheduledTime = ScheduledControlTypes::scheduledTime((string) $message->control_type, (string) $message->send_time);
                if ($scheduledTime === null) {
                    continue;
                }

                if (filled($chatId) && (string) $message->telegramChat?->telegram_chat_id !== $chatId) {
                    continue;
                }
                if (! in_array($day->dayOfWeekIso, array_map('intval', $message->weekdays ?? []), true)) {
                    continue;
                }

                $scheduledFor = $day->setTimeFromTimeString($scheduledTime);
                if ($scheduledFor->gt($dueThrough) || $message->created_at?->greaterThan($scheduledFor)) {
                    continue;
                }
                if ($existing->has($message->id.'|'.$day->toDateString())) {
                    continue;
                }

                $missing[] = [
                    'scheduled_message_id' => $message->id,
                    'control_type' => $message->control_type,
                    'name' => $message->name,
                    'scheduled_for' => $scheduledFor->toIso8601String(),
                ];
            }
        }

        return $missing;
    }

    private function aggregate(array $deliveries, int $missingCount = 0): array
    {
        $rows = collect($deliveries);
        $sent = $rows->where('eligible_for_statistics', true);
        $responded = $sent->where('response_count', '>', 0);
        $totals = [
            'controls' => count($deliveries), 'expected' => count($deliveries) + $missingCount, 'sent' => $sent->count(), 'responded' => $responded->count(),
            'response_rate_percent' => $sent->isEmpty() ? null : (int) round($responded->count() * 100 / $sent->count()),
            'no_response' => $sent->where('result', 'no_response')->count(),
            'pending' => $sent->where('result', 'pending')->count(),
            'not_delivered' => $rows->count() - $sent->count() + $missingCount,
            'response_messages' => $sent->sum('response_count'),
            'median_response_latency_seconds' => $this->statistics->median($responded->pluck('first_response_latency_seconds')->filter(fn ($value) => $value !== null)->all()),
        ];
        foreach (['confirmed', 'problem', 'partial', 'unclear'] as $category) {
            $totals[$category] = $sent->where('result', $category)->count();
        }

        return $totals;
    }
}
