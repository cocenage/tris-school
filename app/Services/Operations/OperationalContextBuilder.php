<?php

namespace App\Services\Operations;

use App\Models\CalendarEvent;
use App\Models\ControlResponse;
use App\Models\DayOffRequestDay;
use App\Models\MobilityAlert;
use App\Models\Task;
use App\Models\TrisMareSnapshot;
use App\Models\VacationRequestDay;
use App\Services\Calendar\CalendarEventsService;
use App\Services\Calendar\CalendarSummaryService;
use App\Services\Mobility\MobilityAlertSyncService;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Read-only aggregation of the existing operational sources.
 *
 * This class deliberately does not call external services, send Telegram
 * messages, dispatch jobs, or update application data. It is the input layer
 * for future deterministic analytics and, later, AI-generated prose.
 */
class OperationalContextBuilder
{
    public function __construct(
        protected CalendarSummaryService $calendarSummaryService,
        protected CalendarEventsService $calendarEventsService,
        protected MobilityAlertSyncService $mobilityNormalizer,
    ) {}

    public function build(Carbon|string|null $date = null): array
    {
        $day = $date
            ? Carbon::parse($date, config('app.timezone', 'Europe/Rome'))->startOfDay()
            : now(config('app.timezone', 'Europe/Rome'))->startOfDay();

        $quality = [];

        $staff = $this->source($quality, 'staff_and_shift', fn () => $this->staff($day));
        $calendar = $this->source($quality, 'calendar', fn () => $this->calendar($day));
        $requests = $this->source($quality, 'requests', fn () => $this->requests($day));
        $tasks = $this->source($quality, 'tasks', fn () => $this->tasks($day));
        $checks = $this->source($quality, 'checks', fn () => $this->checks($day));
        $trisMare = $this->source($quality, 'tris_mare', fn () => $this->trisMare($day));
        $mobility = $this->source($quality, 'mobility', fn () => $this->mobility($day));
        $telegram = $this->source($quality, 'telegram', fn () => $this->telegram($day));

        $context = [
            'date' => $day->toDateString(),
            'timezone' => $day->getTimezone()->getName(),
            'staff' => $staff,
            'calendar' => $calendar,
            'requests' => $requests,
            'tasks' => $tasks,
            'checks' => $checks,
            'tris_mare' => $trisMare,
            'mobility' => $mobility,
            'telegram' => $telegram,
            'risks' => [],
            'data_quality' => $quality,
        ];

        $context['risks'] = $this->risks($context);

        return $context;
    }

    protected function source(array &$quality, string $name, callable $callback): array
    {
        try {
            $value = $callback();
            $hasData = is_array($value)
                && array_key_exists('available_for_date', $value)
                && $value['available_for_date'] === false
                ? false
                : $this->hasData($value);

            $quality[$name] = [
                'status' => $hasData ? 'available' : 'empty',
            ];

            return is_array($value) ? $value : ['value' => $value];
        } catch (Throwable $exception) {
            $quality[$name] = [
                'status' => 'unavailable',
                'reason' => 'Источник недоступен: ' . class_basename($exception),
            ];

            return [
                'available' => false,
                'error' => 'Источник недоступен',
            ];
        }
    }

    protected function staff(Carbon $day): array
    {
        $summary = $this->calendarSummaryService->build($day);

        return [
            'shift' => $this->scalarize($summary['shift'] ?? []),
            'working' => collect($summary['workers']['working'] ?? [])
                ->map(fn ($user) => $this->user($user))
                ->values()
                ->all(),
            'not_working' => collect($summary['workers']['not_working'] ?? [])
                ->map(fn ($user) => $this->user($user, [
                    'reason' => mb_strimwidth(
                        (string) ($user->not_working_reason ?? 'Не работает'),
                        0,
                        160,
                        '…',
                    ),
                ]))
                ->values()
                ->all(),
        ];
    }

    protected function calendar(Carbon $day): array
    {
        return [
            'events' => $this->calendarEventsService
                ->getEventsForDay($day)
                ->map(fn (array $event) => [
                    'id' => $event['id'] ?? null,
                    'type' => $event['type'] ?? 'other',
                    'title' => (string) ($event['title'] ?? ''),
                    'description' => filled($event['description'] ?? null)
                        ? mb_strimwidth((string) $event['description'], 0, 240, '…')
                        : null,
                    'priority' => (int) ($event['priority'] ?? 0),
                    'start' => $this->dateValue($event['start'] ?? null),
                    'end' => $this->dateValue($event['end'] ?? null),
                ])
                ->values()
                ->all(),
        ];
    }

    protected function requests(Carbon $day): array
    {
        $dayOff = DayOffRequestDay::query()
            ->with(['user', 'request'])
            ->whereDate('date', $day->toDateString())
            ->get()
            ->map(fn (DayOffRequestDay $item) => [
                'type' => 'day_off',
                'id' => $item->request?->id,
                'day_id' => $item->id,
                'user' => $item->user?->name,
                'status' => $item->status,
                'request_status' => $item->request?->status,
            ]);

        $vacation = VacationRequestDay::query()
            ->with(['user', 'request'])
            ->whereDate('date', $day->toDateString())
            ->get()
            ->map(fn (VacationRequestDay $item) => [
                'type' => 'vacation',
                'id' => $item->request?->id,
                'day_id' => $item->id,
                'user' => $item->user?->name,
                'status' => $item->status,
                'request_status' => $item->request?->status,
            ]);

        $items = $dayOff->concat($vacation)->values();

        return [
            'total' => $items->count(),
            'by_status' => $items->groupBy('status')->map->count()->all(),
            'items' => $items->all(),
        ];
    }

    protected function tasks(Carbon $day): array
    {
        $items = Task::query()
            ->with(['assignee', 'assignees', 'room', 'board', 'column'])
            ->where(function ($query) use ($day) {
                $query
                    ->whereDate('starts_at', $day->toDateString())
                    ->orWhereDate('deadline_at', $day->toDateString())
                    ->orWhere(function ($query) use ($day) {
                        $query
                            ->whereDate('starts_at', '<=', $day->toDateString())
                            ->whereDate('deadline_at', '>=', $day->toDateString());
                    });
            })
            ->get();

        return [
            'total' => $items->count(),
            'open' => $items->whereNotIn('status', ['done', 'cancelled'])->count(),
            'overdue' => $items->filter(fn (Task $task) => $task->isOverdue())->count(),
            'items' => $items->map(fn (Task $task) => [
                'id' => $task->id,
                'title' => $task->title,
                'status' => $task->status,
                'priority' => $task->priority,
                'deadline_at' => $task->deadline_at?->toIso8601String(),
                'assignees' => $task->assignees->pluck('name')->when(
                    $task->assignees->isEmpty() && $task->assignee,
                    fn (Collection $names) => $names->push($task->assignee->name),
                )->values()->all(),
                'room' => $task->room?->title,
            ])->values()->all(),
        ];
    }

    protected function checks(Carbon $day): array
    {
        $items = ControlResponse::query()
            ->where(function ($query) use ($day) {
                $query
                    ->whereDate('cleaning_date', $day->toDateString())
                    ->orWhereDate('inspection_date', $day->toDateString());
            })
            ->get();

        return [
            'total' => $items->count(),
            'by_zone' => $items->groupBy('result_zone')->map->count()->all(),
            'critical' => $items->where('has_critical_failure', true)->count(),
            'average_score_percent' => $items->whereNotNull('score_percent')->avg('score_percent'),
            'items' => $items->map(fn (ControlResponse $response) => [
                'id' => $response->id,
                'cleaner_id' => $response->cleaner_id,
                'apartment_id' => $response->apartment_id,
                'result_zone' => $response->result_zone,
                'score_percent' => $response->score_percent,
                'errors_count' => $response->errors_count,
                'has_critical_failure' => (bool) $response->has_critical_failure,
                'inspection_date' => $response->inspection_date?->toDateString(),
            ])->values()->all(),
        ];
    }

    protected function trisMare(Carbon $day): array
    {
        $latestSyncedAt = TrisMareSnapshot::query()->max('synced_at');

        if ($latestSyncedAt && Carbon::parse(
            $latestSyncedAt,
            config('app.timezone', 'Europe/Rome'),
        )->isAfter($day->copy()->endOfDay())) {
            return [
                'total' => 0,
                'items' => [],
                'available_for_date' => false,
                'latest_synced_at' => Carbon::parse(
                    $latestSyncedAt,
                    config('app.timezone', 'Europe/Rome'),
                )->toIso8601String(),
                'reason' => 'Последний снимок TRIS Mare новее выбранной даты',
            ];
        }

        $items = TrisMareSnapshot::query()->with('user')->get();

        return [
            'total' => $items->count(),
            'available_for_date' => $items->isNotEmpty(),
            'latest_synced_at' => $latestSyncedAt
                ? Carbon::parse($latestSyncedAt, config('app.timezone', 'Europe/Rome'))->toIso8601String()
                : null,
            'items' => $items->map(fn (TrisMareSnapshot $item) => [
                'user_id' => $item->user_id,
                'employee_name' => $item->employee_name,
                'daily_points' => $item->daily_points,
                'weekly_points' => $item->weekly_points,
                'progress_percent' => $item->progress_percent,
                'status' => $item->status,
                'rating' => $item->rating,
                'comment' => filled($item->comment)
                    ? mb_strimwidth((string) $item->comment, 0, 240, '…')
                    : null,
                'synced_at' => $item->synced_at?->toIso8601String(),
            ])->values()->all(),
        ];
    }

    protected function mobility(Carbon $day): array
    {
        $items = MobilityAlert::query()
            ->where(function ($query) use ($day) {
                $query
                    ->whereDate('starts_at', $day->toDateString())
                    ->orWhere(function ($query) use ($day) {
                        $query
                            ->whereDate('starts_at', '<=', $day->toDateString())
                            ->whereDate('ends_at', '>=', $day->toDateString());
                    });
            })
            ->orderByRaw("CASE risk WHEN 'critical' THEN 1 WHEN 'high' THEN 2 WHEN 'medium' THEN 3 ELSE 4 END")
            ->orderBy('starts_at')
            ->get();

        $normalized = $items
            ->flatMap(fn (MobilityAlert $alert) => $this->normalizeMobilityAlert($alert))
            ->filter()
            ->sortByDesc(fn (array $alert): int => mb_strlen($alert['summary'] ?? $alert['title'] ?? ''))
            ->unique(fn (array $alert): string => $alert['canonical_key'])
            ->map(function (array $alert): array {
                unset($alert['canonical_key']);

                return $alert;
            })
            ->values();

        return [
            'total' => $normalized->count(),
            'by_risk' => $normalized->groupBy('risk')->map->count()->all(),
            'items' => $normalized->all(),
        ];
    }

    protected function normalizeMobilityAlert(MobilityAlert $alert): array
    {
        $title = $this->cleanMobilityText($alert->title);
        $description = $this->cleanMobilityText($alert->description);

        $lineEvents = $this->mobilityNormalizer->splitTelegramStatusEvents($title);

        if ($lineEvents === [] && $this->mobilityNormalizer->isTelegramStatusMessage($title)) {
            return [];
        }

        if ($lineEvents !== []) {
            return collect($lineEvents)
                ->map(fn (array $event): array => $this->normalizedMobilityItem(
                    alert: $alert,
                    title: $event['title'],
                    description: $event['description'],
                    type: $event['type'],
                    risk: $event['risk'],
                    district: $event['line'],
                ))
                ->all();
        }

        if ($title === '' || in_array($title, ['regolare', 'servizio regolare'], true)) {
            return [];
        }

        $risk = $alert->risk;
        $lower = mb_strtolower($title . ' ' . $description);

        if (str_contains($lower, 'regolare') || str_contains($lower, 'servizio normale')) {
            $risk = 'low';
        }

        return [[
            'id' => $alert->id,
            'source' => $alert->source,
            'type' => $alert->type ?: 'info',
            'risk' => $risk ?: 'low',
            'title' => $title,
            'summary' => $description !== '' ? $description : $title,
            'district' => $alert->district,
            'starts_at' => $alert->starts_at?->toDateString(),
            'ends_at' => $alert->ends_at?->toDateString(),
            'url' => $alert->url,
            'canonical_key' => $this->mobilityNormalizer->canonicalFingerprint(
                $alert->source ?? 'mobility',
                $title,
                $description,
                $alert->type ?: 'info',
                $alert->starts_at?->toDateString(),
            ),
            'impact' => 'unknown',
            'impact_reason' => 'Связь между транспортом и маршрутом сотрудника не определена',
        ]];
    }

    protected function normalizedMobilityItem(
        MobilityAlert $alert,
        string $title,
        string $description,
        string $type,
        string $risk,
        ?string $district,
    ): array {
        $lower = mb_strtolower($title . ' ' . $description);
        $line = preg_match('/\b(M[1-5])\b/i', $lower, $lineMatch)
            ? mb_strtoupper($lineMatch[1])
            : null;
        $summary = $this->mobilitySummary($line, $type, $lower, $description ?: $title);

        return [
            'id' => $alert->id,
            'source' => $alert->source,
            'type' => $type,
            'risk' => $risk,
            'title' => $title,
            'summary' => $summary,
            'district' => $district,
            'starts_at' => $alert->starts_at?->toDateString(),
            'ends_at' => $alert->ends_at?->toDateString(),
            'url' => $alert->url,
            'canonical_key' => $this->mobilityNormalizer->canonicalFingerprint(
                $alert->source ?? 'mobility',
                $title,
                $description,
                $type,
                $alert->starts_at?->toDateString(),
            ),
            'impact' => 'unknown',
            'impact_reason' => 'РЎРІСЏР·СЊ РјРµР¶РґСѓ С‚СЂР°РЅСЃРїРѕСЂС‚РѕРј Рё РјР°СЂС€СЂСѓС‚РѕРј СЃРѕС‚СЂСѓРґРЅРёРєР° РЅРµ РѕРїСЂРµРґРµР»РµРЅР°',
        ];
    }

    protected function mobilitySummary(?string $line, string $type, string $lower, string $fallback): string
    {
        $prefix = $line ? $line . ' — ' : '';

        if ($type === 'partial_closure') {
            if ($line === 'M2' && str_contains($lower, 'crescenzago')) {
                return 'M2 — частично ограничено движение между Gobba и Cologno Nord, работают автобусы BM2.';
            }

            return $prefix . 'частично ограничено движение.';
        }

        if ($type === 'closure') {
            return $prefix . 'линия закрыта.';
        }

        return $fallback;
    }

    protected function cleanMobilityText(?string $value): string
    {
        $value = preg_replace('/https?:\/\/\S+/iu', '', strip_tags((string) $value));
        $value = preg_replace('/milanmetrost[a-z.\s]*[èe] anche su chatgpt/iu', '', $value);
        $value = preg_replace('/\s+/u', ' ', $value);

        return trim($value);
    }

    protected function telegram(Carbon $day): array
    {
        $analytics = DB::connection('analytics');

        $workChatIds = $analytics->table('telegram_chats')
            ->whereIn('type', ['group', 'supergroup', 'channel'])
            ->select('id');

        $messages = $analytics->table('telegram_messages')
            ->whereIn('telegram_chat_id', $workChatIds)
            ->whereBetween('sent_at', [$day->copy()->startOfDay(), $day->copy()->endOfDay()]);

        $signals = [
            'potential_problems' => [
                'pattern' => '/(проблем|не работает|нет ключ|задерж|опозд|слом|не успе|не могу)/ui',
                'label' => 'Потенциальные проблемы',
            ],
            'positive_signals' => [
                'pattern' => '/(спасибо|молодец|помог|решено|готово|отлично)/ui',
                'label' => 'Положительные сигналы',
            ],
        ];

        $signalCounts = [];

        foreach ($signals as $key => $signal) {
            $signalQuery = (clone $messages)
                ->where(function ($query) use ($signal) {
                    foreach ($this->telegramKeywords($signal['pattern']) as $keyword) {
                        $query
                            ->orWhere('text', 'like', '%' . $keyword . '%')
                            ->orWhere('caption', 'like', '%' . $keyword . '%');
                    }
                });

            $signalCounts[$key] = [
                'label' => $signal['label'],
                'count' => $signalQuery->count(),
            ];
        }

        return [
            'messages' => (clone $messages)->count(),
            'chats' => (clone $messages)->distinct()->pluck('telegram_chat_id')->count(),
            'topics' => (clone $messages)->whereNotNull('telegram_topic_id')->distinct()->pluck('telegram_topic_id')->count(),
            'authors' => (clone $messages)->whereNotNull('telegram_user_id')->distinct()->pluck('telegram_user_id')->count(),
            'attachments' => $analytics->table('telegram_attachments')
                ->whereIn('telegram_message_id', (clone $messages)->select('id'))
                ->count(),
            'signals' => $signalCounts,
            'private_messages_excluded' => true,
        ];
    }

    protected function telegramKeywords(string $pattern): array
    {
        return str_contains($pattern, 'проблем')
            ? ['проблем', 'не работает', 'нет ключ', 'задерж', 'опозд', 'слом', 'не успе', 'не могу']
            : ['спасибо', 'молодец', 'помог', 'решено', 'готово', 'отлично'];
    }

    protected function risks(array $context): array
    {
        $risks = [];

        $shift = $context['staff']['shift'] ?? [];

        if (($shift['level'] ?? null) === 'critical') {
            $risks[] = ['level' => 'critical', 'code' => 'low_staffing', 'message' => 'Критически низкая доля работающих сотрудников', 'source' => 'staff_and_shift'];
        } elseif (($shift['level'] ?? null) === 'warning') {
            $risks[] = ['level' => 'warning', 'code' => 'reduced_staffing', 'message' => 'Сниженная доля работающих сотрудников', 'source' => 'staff_and_shift'];
        }

        foreach ($context['calendar']['events'] ?? [] as $event) {
            if (($event['type'] ?? null) === CalendarEvent::TYPE_PEAK) {
                $risks[] = ['level' => 'warning', 'code' => 'peak_day', 'message' => 'В календаре отмечен пик нагрузки: ' . $event['title'], 'source' => 'calendar'];
            }
        }

        if (($context['tasks']['overdue'] ?? 0) > 0) {
            $risks[] = ['level' => 'warning', 'code' => 'overdue_tasks', 'message' => 'Есть просроченные задачи', 'source' => 'tasks'];
        }

        if (($context['checks']['critical'] ?? 0) > 0) {
            $risks[] = ['level' => 'critical', 'code' => 'critical_checks', 'message' => 'Есть проверки с критической ошибкой', 'source' => 'checks'];
        }

        foreach ($context['mobility']['items'] ?? [] as $alert) {
            if (in_array($alert['risk'] ?? null, ['critical', 'high'], true)) {
                $risks[] = ['level' => $alert['risk'], 'code' => 'mobility_alert', 'message' => $alert['title'], 'source' => 'mobility', 'impact' => $alert['impact'] ?? 'unknown'];
            }
        }

        if (($context['telegram']['signals']['potential_problems']['count'] ?? 0) > 0) {
            $risks[] = ['level' => 'info', 'code' => 'telegram_signals', 'message' => 'В рабочих чатах обнаружены сообщения с потенциальными проблемами', 'source' => 'telegram'];
        }

        return $risks;
    }

    protected function user(mixed $user, array $extra = []): array
    {
        return array_merge([
            'id' => $user->id ?? null,
            'name' => $user->name ?? null,
            'role' => $user->role ?? null,
        ], $extra);
    }

    protected function scalarize(mixed $value): mixed
    {
        if ($value instanceof Collection) {
            return $value->map(fn ($item) => $this->scalarize($item))->all();
        }

        if (is_array($value)) {
            return collect($value)->map(fn ($item) => $this->scalarize($item))->all();
        }

        if ($value instanceof Carbon) {
            return $value->toIso8601String();
        }

        if (is_object($value)) {
            return null;
        }

        return $value;
    }

    protected function dateValue(mixed $value): ?string
    {
        if ($value instanceof Carbon) {
            return $value->toDateString();
        }

        return filled($value) ? Carbon::parse($value)->toDateString() : null;
    }

    protected function hasData(mixed $value): bool
    {
        if (is_array($value)) {
            foreach ($value as $item) {
                if ($this->hasData($item)) {
                    return true;
                }
            }

            return false;
        }

        if ($value instanceof Collection) {
            return $value->isNotEmpty();
        }

        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (float) $value !== 0.0;
        }

        return filled($value);
    }
}
