<?php

use App\Models\CalendarEvent;
use App\Models\DayOffRequestDay;
use App\Models\User;
use App\Models\VacationRequest;
use App\Services\Calendar\TaskCalendarEventService;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Livewire\Component;

new class extends Component {
    public Carbon $month;
    public ?string $selectedDate = null;
    public bool $sheetOpen = false;
    public string $activeFilter = 'all';
    public string $calendarMode = 'calendar';
    public string $daySheetTab = 'events';

    protected array $shiftSummaryCache = [];
    protected array $shiftSummaryRangeCache = [];
    protected ?Collection $cleanerIdsCache = null;

    public function mount(): void
    {
        Carbon::setLocale('ru');

        $this->month = now()->startOfMonth();
        $this->selectedDate = now()->toDateString();
    }

    public function prevMonth(): void
    {
        $this->month = $this->month->copy()->subMonth()->startOfMonth();
    }

    public function nextMonth(): void
    {
        $this->month = $this->month->copy()->addMonth()->startOfMonth();
    }

    protected function normalizeWeekendDays(mixed $value): Collection
    {
        if ($value instanceof Collection) {
            return $value->map(fn ($day) => (int) $day)->filter()->values();
        }

        if (is_array($value)) {
            return collect($value)->map(fn ($day) => (int) $day)->filter()->values();
        }

        if (is_string($value) && filled($value)) {
            $decoded = json_decode($value, true);

            if (is_array($decoded)) {
                return collect($decoded)->map(fn ($day) => (int) $day)->filter()->values();
            }

            return collect(explode(',', $value))->map(fn ($day) => (int) trim($day))->filter()->values();
        }

        return collect();
    }

    protected function isRegularWeekend(User $user, Carbon $date): bool
    {
        return $this->normalizeWeekendDays($user->weekend_days ?? [])->contains($date->dayOfWeekIso);
    }

    public function getSelectedDayWorkersProperty(): array
    {
        $selectedDay = $this->selectedDay->copy()->startOfDay();
        $date = $selectedDay->toDateString();

        $users = User::query()
            ->activeStaff()
            ->where('role', 'cleaner')
            ->orderBy('name')
            ->get();

        $userIds = $users->pluck('id');

        $dayOffDays = DayOffRequestDay::query()
            ->with(['request'])
            ->whereDate('date', $date)
            ->where('status', 'approved')
            ->whereIn('user_id', $userIds)
            ->get()
            ->keyBy('user_id');

        $vacationRequests = VacationRequest::query()
            ->with(['days' => fn ($q) => $q
                ->where('status', 'approved')
                ->whereDate('date', $date)
                ->orderBy('date')
            ])
            ->whereIn('user_id', $userIds)
            ->whereHas('days', fn ($q) => $q
                ->where('status', 'approved')
                ->whereDate('date', $date)
            )
            ->get()
            ->keyBy('user_id');

        $notWorking = $users->filter(function ($user) use ($selectedDay, $dayOffDays, $vacationRequests) {
            return $this->isRegularWeekend($user, $selectedDay)
                || $dayOffDays->has($user->id)
                || $vacationRequests->has($user->id);
        })->map(function ($user) use ($selectedDay, $dayOffDays, $vacationRequests) {
            if ($vacationRequests->has($user->id)) {
                $request = $vacationRequests->get($user->id);

                $user->not_working_reason = filled($request?->reason)
                    ? 'Отпуск: ' . $request->reason
                    : 'Отпуск';
            } elseif ($dayOffDays->has($user->id)) {
                $day = $dayOffDays->get($user->id);

                $user->not_working_reason = filled($day?->request?->reason)
                    ? 'Выходной: ' . $day->request->reason
                    : 'Выходной';
            } elseif ($this->isRegularWeekend($user, $selectedDay)) {
                $user->not_working_reason = 'Регулярный выходной';
            }

            return $user;
        })->values();

        $working = $users
            ->whereNotIn('id', $notWorking->pluck('id'))
            ->values();

        return [
            'total' => $users->count(),
            'working_count' => $working->count(),
            'not_working_count' => $notWorking->count(),
            'working_percent' => $users->count() > 0
                ? (int) round(($working->count() / $users->count()) * 100)
                : 0,
            'working' => $working,
            'not_working' => $notWorking,
        ];
    }

    public function getSelectedDaySupervisorsProperty(): Collection
    {
        return User::query()
            ->activeStaff()
            ->where('role', 'supervisor')
            ->orderBy('name')
            ->get();
    }

    public function getSelectedDayShiftSummaryProperty(): array
    {
        return $this->getDayShiftSummary($this->selectedDay);
    }

    public function getProblemShiftDaysProperty(): array
    {
        $days = [];
        $cursor = $this->month->copy()->startOfMonth();
        $end = $this->month->copy()->endOfMonth();

        while ($cursor->lte($end)) {
            $summary = $this->getDayShiftSummary($cursor);

            if ($summary['level'] !== 'good' && $summary['total'] > 0) {
                $days[] = [
                    'date' => $cursor->copy(),
                    'summary' => $summary,
                ];
            }

            $cursor->addDay();
        }

        return collect($days)
            ->sortBy(fn ($day) => $day['summary']['working_percent'])
            ->take(5)
            ->values()
            ->all();
    }

    protected function getDayShiftSummary(Carbon $date): array
    {
        $key = $date->toDateString();

        if (isset($this->shiftSummaryCache[$key])) {
            return $this->shiftSummaryCache[$key];
        }

        $rangeStart = $date->copy()->startOfMonth()->startOfWeek(Carbon::MONDAY);
        $rangeEnd = $date->copy()->endOfMonth()->endOfWeek(Carbon::SUNDAY);

        $this->buildShiftSummaryCacheForRange($rangeStart, $rangeEnd);

        return $this->shiftSummaryCache[$key] ?? $this->makeShiftSummary(0, 0);
    }

    protected function buildShiftSummaryCacheForRange(Carbon $rangeStart, Carbon $rangeEnd): void
    {
        $rangeKey = $rangeStart->toDateString() . '_' . $rangeEnd->toDateString();

        if (isset($this->shiftSummaryRangeCache[$rangeKey])) {
            return;
        }

        $this->shiftSummaryRangeCache[$rangeKey] = true;

        $cleaners = User::query()
            ->activeStaff()
            ->where('role', 'cleaner')
            ->get(['id', 'weekend_days']);

        $cleanerIds = $cleaners->pluck('id');
        $total = $cleanerIds->count();
        $notWorkingByDate = [];

        $dayOffDays = DayOffRequestDay::query()
            ->select(['date', 'user_id', 'status'])
            ->where('status', 'approved')
            ->whereBetween('date', [$rangeStart->toDateString(), $rangeEnd->toDateString()])
            ->whereIn('user_id', $cleanerIds)
            ->get();

        foreach ($dayOffDays as $day) {
            $key = Carbon::parse($day->date)->toDateString();
            $notWorkingByDate[$key][$day->user_id] = true;
        }

        $vacationRequests = VacationRequest::query()
            ->select(['id', 'user_id'])
            ->with(['days' => function ($query) use ($rangeStart, $rangeEnd) {
                $query
                    ->select(['id', 'vacation_request_id', 'date', 'status'])
                    ->where('status', 'approved')
                    ->whereBetween('date', [$rangeStart->toDateString(), $rangeEnd->toDateString()]);
            }])
            ->whereIn('user_id', $cleanerIds)
            ->whereHas('days', fn ($query) => $query
                ->where('status', 'approved')
                ->whereBetween('date', [$rangeStart->toDateString(), $rangeEnd->toDateString()])
            )
            ->get();

        foreach ($vacationRequests as $request) {
            foreach ($request->days as $day) {
                $key = Carbon::parse($day->date)->toDateString();
                $notWorkingByDate[$key][$request->user_id] = true;
            }
        }

        $cursor = $rangeStart->copy();

        while ($cursor->lte($rangeEnd)) {
            $key = $cursor->toDateString();

            foreach ($cleaners as $cleaner) {
                if ($this->isRegularWeekend($cleaner, $cursor)) {
                    $notWorkingByDate[$key][$cleaner->id] = true;
                }
            }

            $notWorking = isset($notWorkingByDate[$key]) ? count($notWorkingByDate[$key]) : 0;

            $this->shiftSummaryCache[$key] = $this->makeShiftSummary($total, $notWorking);

            $cursor->addDay();
        }
    }

    protected function makeShiftSummary(int $total, int $notWorking): array
    {
        $working = max($total - $notWorking, 0);

        $percent = $total > 0
            ? (int) round(($working / $total) * 100)
            : 0;

        $level = match (true) {
            $percent < 60 => 'critical',
            $percent < 80 => 'warning',
            default => 'good',
        };

        $label = match ($level) {
            'critical' => 'Критическая смена',
            'warning' => 'Средняя нагрузка',
            default => 'Нормальная смена',
        };

        $colors = match ($level) {
            'critical' => ['bg' => '#FFE1E1', 'text' => '#9F1D1D'],
            'warning' => ['bg' => '#FFF1C7', 'text' => '#8A6500'],
            default => ['bg' => '#E7F6EC', 'text' => '#2F7D4A'],
        };

        return [
            'total' => $total,
            'working' => $working,
            'not_working' => $notWorking,
            'working_percent' => $percent,
            'level' => $level,
            'label' => $label,
            'bg' => $colors['bg'],
            'text' => $colors['text'],
        ];
    }

    public function setFilter(string $filter): void
    {
        if ($filter === 'day_off') {
            if (! $this->canViewCalendarType('vacation')) {
                $this->activeFilter = 'all';
                return;
            }

            $this->activeFilter = $filter;
            return;
        }

        if ($filter !== 'all' && ! $this->canViewCalendarType($filter)) {
            $this->activeFilter = 'all';
            return;
        }

        $this->activeFilter = $filter;
    }

    public function setCalendarMode(string $mode): void
    {
        if (! in_array($mode, ['calendar', 'shift', 'people'], true)) {
            return;
        }

        $this->calendarMode = $mode;
    }

    public function setDaySheetTab(string $tab): void
    {
        if (! in_array($tab, ['events', 'people'], true)) {
            return;
        }

        $this->daySheetTab = $tab;
    }

    public function openDay(string $date): void
    {
        $this->selectedDate = $date;
        $this->daySheetTab = 'events';
        $this->sheetOpen = true;
    }

    public function closeSheet(): void
    {
        $this->sheetOpen = false;
    }

    public function shiftSelectedWeek(int $direction): void
    {
        $current = $this->selectedDate
            ? Carbon::parse($this->selectedDate)
            : now();

        $next = $current->copy()->addWeeks($direction);

        $this->selectedDate = $next->toDateString();
        $this->sheetOpen = true;
    }

    public function getFiltersProperty(): array
    {
        $all = [
            'workflow' => 'Рабочие процессы',
            'tasks' => 'Задачи',
            'finance' => 'Финансы',
            'holiday' => 'Праздники',
            'peak' => 'Пики загрузки',
            'strike' => 'Забастовки',
        ];

        $allowed = auth()->user()?->allowedCalendarTypes() ?? [];

        $filters = collect($all)
            ->only($allowed)
            ->toArray();

        if (in_array('vacation', $allowed, true)) {
            $filters['day_off'] = 'Выходные';
            $filters['vacation'] = 'Отпуска';
        }

        return [
            'all' => 'Все',
            ...$filters,
        ];
    }

    protected function allowedCalendarTypes(): array
    {
        return auth()->user()?->allowedCalendarTypes() ?? [];
    }

    protected function canViewCalendarType(string $type): bool
    {
        if ($type === 'day_off') {
            return in_array('vacation', $this->allowedCalendarTypes(), true);
        }

        return in_array($type, $this->allowedCalendarTypes(), true);
    }

    public function getWeeksForMonth(Carbon $month): array
    {
        $calendarStart = $month
            ->copy()
            ->startOfMonth()
            ->startOfWeek(Carbon::MONDAY);

        $calendarEnd = $month
            ->copy()
            ->endOfMonth()
            ->endOfWeek(Carbon::SUNDAY);

        $events = $this->getExpandedEvents(
            $calendarStart->copy(),
            $calendarEnd->copy(),
        );

        $weeks = [];
        $cursor = $calendarStart->copy();

        while ($cursor->lte($calendarEnd)) {
            $weekStart = $cursor->copy();
            $weekEnd = $cursor->copy()->endOfWeek(Carbon::SUNDAY);

            $days = [];

            for ($i = 0; $i < 7; $i++) {
                $day = $weekStart->copy()->addDays($i);

                $days[] = [
                    'date' => $day,
                    'inMonth' => $day->month === $month->month,
                    'isToday' => $day->isToday(),
                    'count' => $events->filter(
                        fn (array $event) => $day->betweenIncluded($event['start'], $event['end'])
                    )->count(),
                ];
            }

            $weekEvents = $events
                ->filter(fn (array $event) => $event['start']->lte($weekEnd) && $event['end']->gte($weekStart))
                ->sortBy([
                    ['priority', 'desc'],
                    ['start', 'asc'],
                ])
                ->values();

            $tracks = $this->buildWeekTracks($weekEvents, $weekStart, $weekEnd);

            $weeks[] = [
                'days' => $days,
                'tracks' => $tracks,
            ];

            $cursor->addWeek();
        }

        return $weeks;
    }

    protected function buildWeekTracks(Collection $weekEvents, Carbon $weekStart, Carbon $weekEnd): array
    {
        $tracks = [];
        $laneIndexes = [];

        $weekEvents = $weekEvents
            ->sortBy([
                ['lane_title', 'asc'],
                ['priority', 'desc'],
                ['start', 'asc'],
            ])
            ->values();

        foreach ($weekEvents as $event) {
            $visibleStart = $event['start']->lt($weekStart) ? $weekStart : $event['start'];
            $visibleEnd = $event['end']->gt($weekEnd) ? $weekEnd : $event['end'];

            $colStart = $weekStart->diffInDays($visibleStart) + 1;
            $colEnd = $weekStart->diffInDays($visibleEnd) + 2;
            $laneKey = (string) ($event['lane_key'] ?? $event['id']);

            $trackIndex = $laneIndexes[$laneKey] ?? null;

            if ($trackIndex === null) {
                $trackIndex = count($tracks);
                $laneIndexes[$laneKey] = $trackIndex;
                $tracks[$trackIndex] = [];
            }

            $item = [
                ...$event,
                'colStart' => $colStart,
                'colEnd' => $colEnd,
            ];

            $hasCollision = collect($tracks[$trackIndex])->contains(function ($existing) use ($colStart, $colEnd) {
                return ! ($colEnd <= $existing['colStart'] || $colStart >= $existing['colEnd']);
            });

            if (! $hasCollision) {
                $tracks[$trackIndex][] = $item;
                continue;
            }

            $placed = false;

            foreach ($tracks as $fallbackIndex => $trackItems) {
                $hasFallbackCollision = collect($trackItems)->contains(function ($existing) use ($colStart, $colEnd) {
                    return ! ($colEnd <= $existing['colStart'] || $colStart >= $existing['colEnd']);
                });

                if (! $hasFallbackCollision) {
                    $tracks[$fallbackIndex][] = $item;
                    $placed = true;
                    break;
                }
            }

            if (! $placed) {
                $tracks[] = [$item];
            }
        }

        return array_values($tracks);
    }

    public function getSelectedDayStaffSummaryProperty(): array
    {
        $supervisors = User::query()
            ->activeStaff()
            ->where('role', 'supervisor')
            ->count();

        $cleaners = User::query()
            ->activeStaff()
            ->where('role', 'cleaner')
            ->count();

        return [
            'total' => $supervisors + $cleaners,
            'supervisors' => $supervisors,
            'cleaners' => $cleaners,
            'cleaners_not_working' => $this->selectedDayWorkers['not_working_count'],
        ];
    }

    public function getPeopleDaysForMonth(Carbon $month): array
    {
        $days = [];
        $cursor = $month->copy()->startOfMonth();
        $end = $month->copy()->endOfMonth();

        while ($cursor->lte($end)) {
            $days[] = $cursor->copy();
            $cursor->addDay();
        }

        return $days;
    }

    public function getPeopleRowsForMonth(Carbon $month): array
    {
        $start = $month->copy()->startOfMonth();
        $end = $month->copy()->endOfMonth();
        $dayWidth = 42;

        $users = User::query()
            ->activeStaff()
            ->where('role', 'cleaner')
            ->orderBy('name')
            ->get();

        $events = $this->getExpandedEvents($start->copy(), $end->copy())
            ->filter(fn (array $event) => in_array(($event['type'] ?? null), ['vacation', 'day_off'], true) && filled($event['user_id'] ?? null))
            ->values();

        return $users->map(function ($user) use ($events, $start, $end, $dayWidth) {
            $bars = $events
                ->filter(fn (array $event) => (int) ($event['user_id'] ?? 0) === (int) $user->id)
                ->map(function (array $event) use ($start, $end, $dayWidth) {
                    $visibleStart = $event['start']->lt($start) ? $start : $event['start'];
                    $visibleEnd = $event['end']->gt($end) ? $end : $event['end'];

                    $startIndex = $start->diffInDays($visibleStart);
                    $span = $visibleStart->diffInDays($visibleEnd) + 1;

                    return [
                        ...$event,
                        'left' => $startIndex * $dayWidth + 3,
                        'width' => max($span * $dayWidth - 6, 20),
                    ];
                })
                ->values();

            return [
                'user' => $user,
                'bars' => $bars,
            ];
        })->values()->all();
    }

    public function getCarouselMonthsProperty(): array
    {
        return [
            $this->month->copy()->subMonth()->startOfMonth(),
            $this->month->copy()->startOfMonth(),
            $this->month->copy()->addMonth()->startOfMonth(),
        ];
    }

    public function getDayLettersProperty(): array
    {
        return ['п', 'в', 'с', 'ч', 'п', 'с', 'в'];
    }

    public function getSelectedDayProperty(): Carbon
    {
        return $this->selectedDate
            ? Carbon::parse($this->selectedDate)
            : now();
    }

    public function getSelectedWeekDaysProperty(): array
    {
        $start = $this->selectedDay->copy()->startOfWeek(Carbon::MONDAY);

        $days = [];

        for ($i = 0; $i < 7; $i++) {
            $days[] = $start->copy()->addDays($i);
        }

        return $days;
    }

    public function getSheetCarouselWeeksProperty(): array
    {
        $currentWeekStart = $this->selectedDay
            ->copy()
            ->startOfWeek(Carbon::MONDAY);

        $weeks = [];

        foreach ([-1, 0, 1] as $offset) {
            $start = $currentWeekStart->copy()->addWeeks($offset);

            $days = [];

            for ($i = 0; $i < 7; $i++) {
                $days[] = $start->copy()->addDays($i);
            }

            $weeks[] = $days;
        }

        return $weeks;
    }

    public function getCalendarRangeProperty(): array
    {
        return [
            'start' => $this->month
                ->copy()
                ->startOfMonth()
                ->startOfWeek(Carbon::MONDAY),

            'end' => $this->month
                ->copy()
                ->endOfMonth()
                ->endOfWeek(Carbon::SUNDAY),
        ];
    }

    public function getVisibleEventsProperty(): Collection
    {
        return $this->getExpandedEvents(
            $this->calendarRange['start']->copy(),
            $this->calendarRange['end']->copy(),
        );
    }

    public function getWeeksProperty(): array
    {
        $calendarStart = $this->calendarRange['start']->copy();
        $calendarEnd = $this->calendarRange['end']->copy();

        $events = $this->visibleEvents;

        $weeks = [];
        $cursor = $calendarStart->copy();

        while ($cursor->lte($calendarEnd)) {
            $weekStart = $cursor->copy();
            $weekEnd = $cursor->copy()->endOfWeek(Carbon::SUNDAY);

            $days = [];

            for ($i = 0; $i < 7; $i++) {
                $day = $weekStart->copy()->addDays($i);

                $days[] = [
                    'date' => $day,
                    'inMonth' => $day->month === $this->month->month,
                    'isToday' => $day->isToday(),
                    'count' => $events->filter(
                        fn (array $event) => $day->betweenIncluded($event['start'], $event['end'])
                    )->count(),
                ];
            }

            $weekEvents = $events
                ->filter(fn (array $event) => $event['start']->lte($weekEnd) && $event['end']->gte($weekStart))
                ->sortBy([
                    ['priority', 'desc'],
                    ['start', 'asc'],
                ])
                ->values();

            $tracks = $this->buildWeekTracks($weekEvents, $weekStart, $weekEnd);

            $weeks[] = [
                'days' => $days,
                'tracks' => $tracks,
            ];

            $cursor->addWeek();
        }

        return $weeks;
    }

    public function getSelectedDayEventsProperty(): Collection
    {
        $day = $this->selectedDay->copy()->startOfDay();

        return $this->visibleEvents
            ->filter(function (array $event) use ($day) {
                return $day->betweenIncluded(
                    $event['start']->copy()->startOfDay(),
                    $event['end']->copy()->startOfDay()
                );
            })
            ->sortBy([
                ['priority', 'desc'],
                ['start', 'asc'],
                ['title', 'asc'],
            ])
            ->values();
    }

    public function getSelectedDayEventGroupsProperty(): array
    {
        $groups = [
            'tasks' => ['title' => 'Задачи', 'emoji' => '⚡️', 'events' => collect()],
            'day_off' => ['title' => 'Выходные', 'emoji' => '🌿', 'events' => collect()],
            'vacation' => ['title' => 'Отпуска', 'emoji' => '🏖️', 'events' => collect()],
            'holiday' => ['title' => 'Праздники', 'emoji' => '🎉', 'events' => collect()],
            'workflow' => ['title' => 'Рабочие процессы', 'emoji' => '🛠️', 'events' => collect()],
            'finance' => ['title' => 'Финансы', 'emoji' => '💶', 'events' => collect()],
            'peak' => ['title' => 'Пики загрузки', 'emoji' => '📈', 'events' => collect()],
            'strike' => ['title' => 'Забастовки', 'emoji' => '🚧', 'events' => collect()],
            'other' => ['title' => 'Другое', 'emoji' => '📌', 'events' => collect()],
        ];

        foreach ($this->selectedDayEvents as $event) {
            $type = $event['type'] ?? 'other';
            $key = array_key_exists($type, $groups) ? $type : 'other';

            $groups[$key]['events']->push($event);
        }

        return collect($groups)
            ->filter(fn ($group) => $group['events']->count() > 0)
            ->toArray();
    }

    public function getDayEventBadges(Carbon $day): array
    {
        $events = $this->visibleEvents
            ->filter(fn (array $event) => $day->betweenIncluded(
                $event['start']->copy()->startOfDay(),
                $event['end']->copy()->startOfDay()
            ));

        $counts = [
            'tasks' => $events->where('type', 'tasks')->count(),
            'day_off' => $events->where('type', 'day_off')->count(),
            'vacation' => $events->where('type', 'vacation')->count(),
            'holiday' => $events->where('type', 'holiday')->count(),
            'workflow' => $events->where('type', 'workflow')->count(),
            'finance' => $events->where('type', 'finance')->count(),
            'peak' => $events->where('type', 'peak')->count(),
            'strike' => $events->where('type', 'strike')->count(),
        ];

        return collect([
            ['emoji' => '⚡️', 'count' => $counts['tasks'], 'bg' => '#FFE8B5', 'text' => '#8A5800'],
            ['emoji' => '🌿', 'count' => $counts['day_off'], 'bg' => '#DDF3E4', 'text' => '#2F7D4A'],
            ['emoji' => '🏖️', 'count' => $counts['vacation'], 'bg' => '#ECE3FF', 'text' => '#5B45A0'],
            ['emoji' => '🎉', 'count' => $counts['holiday'], 'bg' => '#FFE1E1', 'text' => '#9F1D1D'],
            ['emoji' => '🛠️', 'count' => $counts['workflow'], 'bg' => '#DDEEFF', 'text' => '#245C9A'],
            ['emoji' => '💶', 'count' => $counts['finance'], 'bg' => '#DDF3E4', 'text' => '#2F7D4A'],
            ['emoji' => '📈', 'count' => $counts['peak'], 'bg' => '#FFF1C7', 'text' => '#8A6500'],
            ['emoji' => '🚧', 'count' => $counts['strike'], 'bg' => '#FCE9DD', 'text' => '#9A4D20'],
        ])
            ->filter(fn ($badge) => $badge['count'] > 0)
            ->values()
            ->take(4)
            ->all();
    }

    protected function formatUserDip(?User $user): string
    {
        if (! $user) {
            return '';
        }

        return $user->dip ? ' • DIP' : ' • NO DIP';
    }

    protected function getExpandedEvents(Carbon $rangeStart, Carbon $rangeEnd): Collection
    {
        $allowedTypes = $this->allowedCalendarTypes();

        if (empty($allowedTypes)) {
            return collect();
        }

$baseEvents = CalendarEvent::query()
    ->where('is_active', true)
    ->whereIn('type', $allowedTypes)
    ->orderByDesc('priority')
    ->orderBy('start_date')
    ->get();

        $expanded = collect();

        foreach ($baseEvents as $event) {
            $expanded = $expanded->merge(
                $this->expandEventOccurrences($event, $rangeStart->copy(), $rangeEnd->copy())
            );
        }

        if ($this->canViewCalendarType('holiday')) {
            $users = User::query()
                ->activeStaff()
                ->where(function ($q) {
                    $q->whereNotNull('birthday')
                        ->orWhereNotNull('work_started_at');
                })
                ->get();

            foreach ($users as $user) {
                if ($user->birthday) {
                    $birthday = Carbon::parse($user->birthday);

                    for ($year = $rangeStart->year - 1; $year <= $rangeEnd->year + 1; $year++) {
                        $date = $this->safeDateForYear($birthday, $year);

                        if ($date && $date->betweenIncluded($rangeStart, $rangeEnd)) {
                            $expanded->push([
                                'id' => 'birthday_' . $user->id . '_' . $year,
                                'title' => "{$user->name}{$this->formatUserDip($user)} — День рождения",
                                'user_id' => $user->id,
                                'lane_key' => 'user_' . $user->id,
                                'lane_title' => $user->name,
                                'description' => null,
                                'short' => mb_strimwidth("🎂 {$user->name}", 0, 12, '...'),
                                'type' => 'holiday',
                                'priority' => 100,
                                'start' => $date->copy()->startOfDay(),
                                'end' => $date->copy()->startOfDay(),
                                'style' => $this->eventStyle('holiday'),
                            ]);
                        }
                    }
                }

                if ($user->work_started_at) {
                    $workStarted = Carbon::parse($user->work_started_at);

                    for ($year = $rangeStart->year - 1; $year <= $rangeEnd->year + 1; $year++) {
                        $date = $this->safeDateForYear($workStarted, $year);

                        if ($date && $date->betweenIncluded($rangeStart, $rangeEnd)) {
                            $years = Carbon::parse($user->work_started_at)->diffInYears($date);

                            if ($years < 1) {
                                continue;
                            }

                            $expanded->push([
                                'id' => 'work_' . $user->id . '_' . $year,
                                'title' => "{$user->name}{$this->formatUserDip($user)} — {$years} лет в компании",
                                'user_id' => $user->id,
                                'lane_key' => 'user_' . $user->id,
                                'lane_title' => $user->name,
                                'description' => null,
                                'short' => mb_strimwidth("🏢 {$user->name}", 0, 12, '...'),
                                'type' => 'holiday',
                                'priority' => 90,
                                'start' => $date->copy()->startOfDay(),
                                'end' => $date->copy()->startOfDay(),
                                'style' => $this->eventStyle('holiday'),
                            ]);
                        }
                    }
                }
            }
        }

        if ($this->canViewCalendarType('vacation')) {
            $regularWeekendUsers = User::query()
                ->activeStaff()
                ->where('role', 'cleaner')
                ->whereNotNull('weekend_days')
                ->orderBy('name')
                ->get();

            $cursor = $rangeStart->copy()->startOfDay();

            while ($cursor->lte($rangeEnd)) {
                foreach ($regularWeekendUsers as $user) {
                    if (! $this->isRegularWeekend($user, $cursor)) {
                        continue;
                    }

                    $expanded->push([
                        'id' => 'regular_day_off_user_' . $user->id . '_' . $cursor->format('Ymd'),
                        'title' => "{$user->name}{$this->formatUserDip($user)} — Регулярный выходной",
                        'user_id' => $user->id,
                        'lane_key' => 'user_' . $user->id,
                        'lane_title' => $user->name,
                        'description' => 'Регулярный выходной по графику',
                        'short' => mb_strimwidth("🌿 {$user->name}", 0, 18, '...'),
                        'type' => 'day_off',
                        'priority' => 70,
                        'start' => $cursor->copy()->startOfDay(),
                        'end' => $cursor->copy()->startOfDay(),
                        'style' => $this->eventStyle('day_off'),
                    ]);
                }

                $cursor->addDay();
            }

            $dayOffDays = DayOffRequestDay::query()
                ->with(['user', 'request'])
                ->where('status', 'approved')
                ->whereBetween('date', [
                    $rangeStart->copy()->toDateString(),
                    $rangeEnd->copy()->toDateString(),
                ])
                ->whereHas('user', fn ($q) => $q->activeStaff())
                ->get();

            $dayOffGroups = $dayOffDays
                ->sortBy('date')
                ->groupBy('user_id');

            foreach ($dayOffGroups as $userId => $userDays) {
                $userDays = $userDays
                    ->sortBy('date')
                    ->values();

                $ranges = [];
                $currentRange = [];

                foreach ($userDays as $day) {
                    if (empty($currentRange)) {
                        $currentRange[] = $day;
                        continue;
                    }

                    $prevDate = Carbon::parse(end($currentRange)->date)->startOfDay();
                    $currentDate = Carbon::parse($day->date)->startOfDay();

                    if ($prevDate->copy()->addDay()->isSameDay($currentDate)) {
                        $currentRange[] = $day;
                    } else {
                        $ranges[] = $currentRange;
                        $currentRange = [$day];
                    }
                }

                if (! empty($currentRange)) {
                    $ranges[] = $currentRange;
                }

                foreach ($ranges as $rangeIndex => $rangeDays) {
                    $firstDay = collect($rangeDays)->first();
                    $lastDay = collect($rangeDays)->last();

                    if (! $firstDay || ! $lastDay) {
                        continue;
                    }

                    $user = $firstDay->user;

                    $start = Carbon::parse($firstDay->date)->startOfDay();
                    $end = Carbon::parse($lastDay->date)->startOfDay();

                    $expanded->push([
                        'id' => 'day_off_user_' . $userId . '_' . $start->format('Ymd') . '_' . $rangeIndex,
                        'title' => "{$user?->name}{$this->formatUserDip($user)} — Выходной",
                        'user_id' => $user?->id,
                        'lane_key' => $user ? 'user_' . $user->id : 'day_off_' . $userId,
                        'lane_title' => $user?->name ?? 'Выходной',
                        'description' => $firstDay->request?->reason,
                        'short' => mb_strimwidth("🌿 " . ($user?->name ?? 'Выходной'), 0, 18, '...'),
                        'type' => 'day_off',
                        'priority' => 85,
                        'start' => $start,
                        'end' => $end,
                        'style' => $this->eventStyle('day_off'),
                    ]);
                }
            }

            $vacationRequests = VacationRequest::query()
                ->with([
                    'user',
                    'days' => function ($query) use ($rangeStart, $rangeEnd) {
                        $query
                            ->where('status', 'approved')
                            ->whereBetween('date', [
                                $rangeStart->copy()->toDateString(),
                                $rangeEnd->copy()->toDateString(),
                            ])
                            ->orderBy('date');
                    },
                ])
                ->whereHas('user', fn ($q) => $q->activeStaff())
                ->whereHas('days', function ($query) use ($rangeStart, $rangeEnd) {
                    $query
                        ->where('status', 'approved')
                        ->whereBetween('date', [
                            $rangeStart->copy()->toDateString(),
                            $rangeEnd->copy()->toDateString(),
                        ]);
                })
                ->get();

            foreach ($vacationRequests as $vacationRequest) {
                $user = $vacationRequest->user;

                $vacationDays = $vacationRequest->days
                    ->filter(fn ($day) => Carbon::parse($day->date)->betweenIncluded($rangeStart, $rangeEnd))
                    ->sortBy('date')
                    ->values();

                if ($vacationDays->isEmpty()) {
                    continue;
                }

                $start = Carbon::parse($vacationDays->first()->date)->startOfDay();
                $end = Carbon::parse($vacationDays->last()->date)->startOfDay();

                $expanded->push([
                    'id' => 'vacation_' . $vacationRequest->id . '_' . $start->format('Ymd'),
                    'title' => "{$user?->name}{$this->formatUserDip($user)} — Отпуск",
                    'user_id' => $user?->id,
                    'lane_key' => $user ? 'user_' . $user->id : 'vacation_' . $vacationRequest->id,
                    'lane_title' => $user?->name ?? 'Отпуск',
                    'description' => $vacationRequest->reason,
                    'short' => mb_strimwidth("🏖 " . ($user?->name ?? 'Отпуск'), 0, 12, '...'),
                    'type' => 'vacation',
                    'priority' => 80,
                    'start' => $start,
                    'end' => $end,
                    'style' => $this->eventStyle('vacation'),
                ]);
            }
        }

        if ($this->canViewCalendarType('tasks') && auth()->user()) {
            $expanded = $expanded->merge(
                app(TaskCalendarEventService::class)->getEvents(
                    auth()->user(),
                    $rangeStart->copy(),
                    $rangeEnd->copy(),
                )
            );
        }

        if ($this->activeFilter !== 'all') {
            $expanded = $expanded
                ->filter(fn (array $event) => $event['type'] === $this->activeFilter)
                ->values();
        }

        return $this->removeDuplicateStaffAbsences($expanded)
            ->sortByDesc('priority')
            ->values();
    }

    protected function removeDuplicateStaffAbsences(Collection $events): Collection
    {
        $vacationDates = [];
        $explicitDayOffDates = [];

        foreach ($events as $event) {
            if (blank($event['user_id'] ?? null)) {
                continue;
            }

            $cursor = $event['start']->copy()->startOfDay();
            $end = $event['end']->copy()->startOfDay();

            while ($cursor->lte($end)) {
                $key = (int) $event['user_id'] . '_' . $cursor->toDateString();

                if (($event['type'] ?? null) === 'vacation') {
                    $vacationDates[$key] = true;
                }

                if (
                    ($event['type'] ?? null) === 'day_off'
                    && ! str_starts_with((string) ($event['id'] ?? ''), 'regular_day_off_user_')
                ) {
                    $explicitDayOffDates[$key] = true;
                }

                $cursor->addDay();
            }
        }

        return $events
            ->reject(function (array $event) use ($vacationDates, $explicitDayOffDates) {
                if (blank($event['user_id'] ?? null)) {
                    return false;
                }

                $key = (int) $event['user_id'] . '_' . $event['start']->copy()->startOfDay()->toDateString();
                $id = (string) ($event['id'] ?? '');

                if (($event['type'] ?? null) === 'day_off' && isset($vacationDates[$key])) {
                    return true;
                }

                if (str_starts_with($id, 'regular_day_off_user_') && isset($explicitDayOffDates[$key])) {
                    return true;
                }

                return false;
            })
            ->values();
    }

    protected function expandEventOccurrences(CalendarEvent $event, Carbon $rangeStart, Carbon $rangeEnd): Collection
    {
        $occurrences = collect();

        $start = Carbon::parse($event->start_date)->startOfDay();
        $end = $event->end_date
            ? Carbon::parse($event->end_date)->startOfDay()
            : $start->copy();

        if ($end->lt($start)) {
            $end = $start->copy();
        }

        $durationDays = $start->diffInDays($end);

        if ($event->repeat_type === 'none') {
            if ($start->lte($rangeEnd) && $end->gte($rangeStart)) {
                $occurrences->push($this->mapEventOccurrence($event, $start, $end));
            }

            return $occurrences;
        }

        if ($event->repeat_type === 'weekly') {
            $cursor = $start->copy();

            while ($cursor->lt($rangeStart)) {
                $cursor->addWeek();
            }

            $cursor->subWeek();

            while ($cursor->lte($rangeEnd)) {
                $occurrenceStart = $cursor->copy();
                $occurrenceEnd = $cursor->copy()->addDays($durationDays);

                if ($event->repeat_until && $occurrenceStart->gt(Carbon::parse($event->repeat_until)->endOfDay())) {
                    break;
                }

                if ($occurrenceStart->lte($rangeEnd) && $occurrenceEnd->gte($rangeStart)) {
                    $occurrences->push($this->mapEventOccurrence($event, $occurrenceStart, $occurrenceEnd));
                }

                $cursor->addWeek();
            }

            return $occurrences;
        }

        if ($event->repeat_type === 'monthly') {
            $cursor = $start->copy()->startOfMonth();

            while ($cursor->lt($rangeStart->copy()->startOfMonth())) {
                $cursor->addMonth();
            }

            $cursor->subMonth();

            while ($cursor->lte($rangeEnd)) {
                $daysInMonth = $cursor->copy()->endOfMonth()->day;
                $day = min($start->day, $daysInMonth);

                $occurrenceStart = $cursor->copy()->day($day)->startOfDay();

                if ($event->repeat_until && $occurrenceStart->gt(Carbon::parse($event->repeat_until)->endOfDay())) {
                    break;
                }

                $occurrenceEnd = $occurrenceStart->copy()->addDays($durationDays);

                if ($occurrenceStart->lte($rangeEnd) && $occurrenceEnd->gte($rangeStart)) {
                    $occurrences->push($this->mapEventOccurrence($event, $occurrenceStart, $occurrenceEnd));
                }

                $cursor->addMonth();
            }

            return $occurrences;
        }

        if ($event->repeat_type === 'yearly') {
            $startYear = $rangeStart->year - 1;
            $endYear = $rangeEnd->year + 1;

            for ($year = $startYear; $year <= $endYear; $year++) {
                $occurrenceStart = $this->safeDateForYear($start, $year);

                if (! $occurrenceStart) {
                    continue;
                }

                if ($event->repeat_until && $occurrenceStart->gt(Carbon::parse($event->repeat_until)->endOfDay())) {
                    continue;
                }

                $occurrenceEnd = $occurrenceStart->copy()->addDays($durationDays);

                if ($occurrenceStart->lte($rangeEnd) && $occurrenceEnd->gte($rangeStart)) {
                    $occurrences->push($this->mapEventOccurrence($event, $occurrenceStart, $occurrenceEnd));
                }
            }
        }

        return $occurrences;
    }

    protected function safeDateForYear(Carbon $source, int $year): ?Carbon
    {
        try {
            return Carbon::create($year, $source->month, $source->day, 0, 0, 0);
        } catch (\Throwable $e) {
            return null;
        }
    }

    protected function mapEventOccurrence(CalendarEvent $event, Carbon $start, Carbon $end): array
    {
        $user = null;

        $title = $event->title;

        if ($user) {
            $title .= ' — ' . $user->name . $this->formatUserDip($user);
        }

        return [
            'id' => $event->id . '_' . $start->format('Ymd'),
            'title' => $title,
            'user_id' => $user?->id,
            'lane_key' => $user ? 'user_' . $user->id : 'event_' . $event->id,
            'lane_title' => $user?->name ?? $title,
            'description' => $event->description,
            'short' => mb_strimwidth($title, 0, 12, '...'),
            'type' => $event->type,
            'priority' => (int) ($event->priority ?? 0),
            'start' => $start,
            'end' => $end,
            'style' => $this->eventStyle($event->type),
        ];
    }

    public function formatEventRange(array $event): string
    {
        $start = $event['start']->copy()->startOfDay();
        $end = $event['end']->copy()->startOfDay();

        if ($start->isSameDay($end)) {
            return $start->translatedFormat('j F Y');
        }

        if ($start->year === $end->year && $start->month === $end->month) {
            return $start->translatedFormat('j') . '–' . $end->translatedFormat('j F Y');
        }

        if ($start->year === $end->year) {
            return $start->translatedFormat('j F') . ' — ' . $end->translatedFormat('j F Y');
        }

        return $start->translatedFormat('j F Y') . ' — ' . $end->translatedFormat('j F Y');
    }

    protected function eventStyle(string $type): string
    {
        return match ($type) {
            'workflow' => 'background:#CFE8FF;color:#111111;',
            'tasks' => 'background:#DDEBFF;color:#111111;',
            'finance' => 'background:#CBEED9;color:#111111;',
            'holiday' => 'background:#F3B8B8;color:#111111;',
            'peak' => 'background:#F3E69C;color:#111111;',
            'day_off' => 'background:#DDF3E4;color:#111111;',
            'vacation' => 'background:#CDBEFF;color:#111111;',
            'strike' => 'background:#F4C9A8;color:#111111;',
            default => 'background:#E9E9E9;color:#111111;',
        };
    }
};
?>

<x-slot:header>
    <div class="w-full h-[73px] flex items-center justify-between px-[15px]">
        <button
            type="button"
            onclick="history.back()"
            class="flex h-[40px] min-w-[40px] items-center justify-center rounded-full group cursor-pointer bg-[#E1E1E1] backdrop-blur-md text-white transition-all duration-300 hover:bg-[#7D7D7D]"
        >
            <x-heroicon-o-arrow-left class="h-[20px] w-[20px] stroke-[2.4] group-active:scale-[0.95]" />
        </button>

        <span class="flex items-center justify-center text-[18px] font-semibold leading-none">
            Календарь
        </span>

        <button
            type="button"
            class="flex h-[40px] min-w-[40px] items-center justify-center rounded-full group cursor-pointer bg-[#E1E1E1] backdrop-blur-md text-white transition-all duration-300 hover:bg-[#7D7D7D]"
        >
            <x-heroicon-o-magnifying-glass class="h-[20px] w-[20px] stroke-[2.4] group-active:scale-[0.95]" />
        </button>
    </div>
</x-slot:header>

<div
    x-data="{
        open: @entangle('sheetOpen').live,
        selectedDateValue: @entangle('selectedDate').live,

        monthTranslateX: -33.3333,
        monthDragStartX: 0,
        monthDragStartY: 0,
        monthDragCurrentX: 0,
        monthDragging: false,
        monthAnimating: false,
        monthGestureLock: null,
        monthMoved: false,

        weekTranslateX: -33.3333,
        weekDragStartX: 0,
        weekDragStartY: 0,
        weekDragCurrentX: 0,
        weekDragging: false,
        weekAnimating: false,
        weekGestureLock: null,

        monthStart(e) {
            if (this.open || this.monthAnimating) return

            const touch = e.touches[0]

            this.monthDragging = true
            this.monthDragStartX = touch.clientX
            this.monthDragStartY = touch.clientY
            this.monthDragCurrentX = touch.clientX
            this.monthGestureLock = null
            this.monthMoved = false
        },

        monthMove(e) {
            if (!this.monthDragging || this.monthAnimating) return

            const touch = e.touches[0]
            const dx = touch.clientX - this.monthDragStartX
            const dy = touch.clientY - this.monthDragStartY

            if (this.monthGestureLock === null) {
                if (Math.abs(dx) > 8 || Math.abs(dy) > 8) {
                    this.monthGestureLock = Math.abs(dx) > Math.abs(dy) ? 'horizontal' : 'vertical'
                }
            }

            if (this.monthGestureLock !== 'horizontal') return

            this.monthMoved = true
            this.monthDragCurrentX = touch.clientX

            const width = this.$refs.monthViewport?.offsetWidth || 1
            const percent = (dx / width) * 100

            this.monthTranslateX = -33.3333 + (percent / 3)
        },

        async monthEnd() {
            if (!this.monthDragging || this.monthAnimating) return

            this.monthDragging = false

            if (this.monthGestureLock !== 'horizontal') {
                this.monthGestureLock = null
                this.monthMoved = false
                this.monthTranslateX = -33.3333
                return
            }

            const dx = this.monthDragCurrentX - this.monthDragStartX
            const width = this.$refs.monthViewport?.offsetWidth || 1
            const threshold = width * 0.18

            if (Math.abs(dx) < threshold) {
                this.monthAnimating = true
                this.monthTranslateX = -33.3333

                setTimeout(() => {
                    this.monthAnimating = false
                    this.monthGestureLock = null
                    this.monthMoved = false
                }, 300)

                return
            }

            if (dx < 0) {
                this.monthAnimating = true
                this.monthTranslateX = -66.6667

                setTimeout(async () => {
                    await $wire.nextMonth()
                    this.monthTranslateX = -33.3333
                    this.monthAnimating = false
                    this.monthGestureLock = null
                    this.monthMoved = false
                }, 300)
            } else {
                this.monthAnimating = true
                this.monthTranslateX = 0

                setTimeout(async () => {
                    await $wire.prevMonth()
                    this.monthTranslateX = -33.3333
                    this.monthAnimating = false
                    this.monthGestureLock = null
                    this.monthMoved = false
                }, 300)
            }
        },

        weekStart(e) {
            if (!this.open || this.weekAnimating) return

            const touch = e.touches[0]

            this.weekDragging = true
            this.weekDragStartX = touch.clientX
            this.weekDragStartY = touch.clientY
            this.weekDragCurrentX = touch.clientX
            this.weekGestureLock = null
        },

        weekMove(e) {
            if (!this.weekDragging || this.weekAnimating) return

            const touch = e.touches[0]
            const dx = touch.clientX - this.weekDragStartX
            const dy = touch.clientY - this.weekDragStartY

            if (this.weekGestureLock === null) {
                if (Math.abs(dx) > 8 || Math.abs(dy) > 8) {
                    this.weekGestureLock = Math.abs(dx) > Math.abs(dy) ? 'horizontal' : 'vertical'
                }
            }

            if (this.weekGestureLock !== 'horizontal') return

            this.weekDragCurrentX = touch.clientX

            const width = this.$refs.weekViewport?.offsetWidth || 1
            const percent = (dx / width) * 100

            this.weekTranslateX = -33.3333 + (percent / 3)
        },

        async weekEnd() {
            if (!this.weekDragging || this.weekAnimating) return

            this.weekDragging = false

            if (this.weekGestureLock !== 'horizontal') {
                this.weekGestureLock = null
                this.weekTranslateX = -33.3333
                return
            }

            const dx = this.weekDragCurrentX - this.weekDragStartX
            const width = this.$refs.weekViewport?.offsetWidth || 1
            const threshold = width * 0.18

            if (Math.abs(dx) < threshold) {
                this.weekAnimating = true
                this.weekTranslateX = -33.3333

                setTimeout(() => {
                    this.weekAnimating = false
                    this.weekGestureLock = null
                }, 260)

                return
            }

            if (dx < 0) {
                this.weekAnimating = true
                this.weekTranslateX = -66.6667

                setTimeout(async () => {
                    await $wire.shiftSelectedWeek(1)
                    this.weekTranslateX = -33.3333
                    this.weekAnimating = false
                    this.weekGestureLock = null
                }, 260)
            } else {
                this.weekAnimating = true
                this.weekTranslateX = 0

                setTimeout(async () => {
                    await $wire.shiftSelectedWeek(-1)
                    this.weekTranslateX = -33.3333
                    this.weekAnimating = false
                    this.weekGestureLock = null
                }, 260)
            }
        },
    }"
    class="h-full w-full flex flex-col overflow-hidden overflow-x-hidden"
>
    <div class="flex-1 overflow-y-auto overflow-x-hidden bg-white rounded-t-[40px] overscroll-y-contain">
        <div class="px-[20px] pt-[18px] pb-[8px] flex items-center justify-between">
            <button
                type="button"
                wire:click="prevMonth"
                class="flex h-[38px] w-[38px] items-center justify-center rounded-full bg-[#F1F1F1] text-[22px] text-[#111]"
            >
                ‹
            </button>

            <div
                class="text-center text-[22px] font-semibold leading-none text-[#111]"
                wire:key="month-title-{{ $month->format('Y-m') }}"
            >
                {{ $month->translatedFormat('F Y') }}
            </div>

            <button
                type="button"
                wire:click="nextMonth"
                class="flex h-[38px] w-[38px] items-center justify-center rounded-full bg-[#F1F1F1] text-[22px] text-[#111]"
            >
                ›
            </button>
        </div>
        <div class="sticky top-0 z-20 bg-white pt-[20px] pb-[15px] overflow-x-hidden">
            <div class="px-[20px] flex gap-[5px] overflow-x-auto overflow-y-hidden no-scrollbar bg-white w-full max-w-full">
                @foreach ($this->filters as $key => $label)
                    @php
                        $filterColors = match ($key) {
                            'all' => [
                                'bg' => '#E1E1E1',
                                'text' => '#2A1F1A',
                                'activeBg' => '#7D7D7D',
                                'activeText' => '#FFFFFF',
                            ],
                            'tasks' => [
    'bg' => '#E4EEFF',
    'text' => '#2A1F1A',
    'activeBg' => '#9FC5FF',
    'activeText' => '#111111',
],
                            'workflow' => [
                                'bg' => '#DDEEFF',
                                'text' => '#2A1F1A',
                                'activeBg' => '#8FC4FF',
                                'activeText' => '#111111',
                            ],
                            'finance' => [
                                'bg' => '#DDF3E4',
                                'text' => '#2A1F1A',
                                'activeBg' => '#8FDCAB',
                                'activeText' => '#111111',
                            ],
                            'holiday' => [
                                'bg' => '#FFE1E1',
                                'text' => '#2A1F1A',
                                'activeBg' => '#F3B8B8',
                                'activeText' => '#111111',
                            ],
                            'peak' => [
                                'bg' => '#FFF1C7',
                                'text' => '#2A1F1A',
                                'activeBg' => '#F3E69C',
                                'activeText' => '#111111',
                            ],
                            'day_off' => [
                                'bg' => '#DDF3E4',
                                'text' => '#2A1F1A',
                                'activeBg' => '#8FDCAB',
                                'activeText' => '#111111',
                            ],
                            'vacation' => [
                                'bg' => '#ECE3FF',
                                'text' => '#2A1F1A',
                                'activeBg' => '#CDBEFF',
                                'activeText' => '#111111',
                            ],
                            'strike' => [
                                'bg' => '#FCE9DD',
                                'text' => '#2A1F1A',
                                'activeBg' => '#F4C9A8',
                                'activeText' => '#111111',
                            ],
                            default => [
                                'bg' => '#E9E9E9',
                                'text' => '#2A1F1A',
                                'activeBg' => '#8E8E8E',
                                'activeText' => '#FFFFFF',
                            ],
                        };

                        $isActive = $activeFilter === $key;
                    @endphp

                    <button
                        type="button"
                        wire:click="setFilter('{{ $key }}')"
                        class="shrink-0 rounded-full text-[16px] px-[11px] py-[6px]"
                        style="
                            background: {{ $isActive ? $filterColors['activeBg'] : $filterColors['bg'] }};
                            color: {{ $isActive ? $filterColors['activeText'] : $filterColors['text'] }};
                        "
                    >
                        {{ $label }}
                    </button>
                @endforeach
            </div>
        </div>




        @if ($calendarMode === 'calendar')

        <div class="grid grid-cols-7 gap-0 mb-[8px] px-[2px]">
            @foreach ($this->dayLetters as $letter)
                <div class="flex justify-center">
                    <span class="text-[14px] leading-none">
                        {{ $letter }}
                    </span>
                </div>
            @endforeach
        </div>

        <div
            x-ref="monthViewport"
            class="overflow-hidden select-none"
            @touchstart.passive="monthStart($event)"
            @touchmove.passive="monthMove($event)"
            @touchend.passive="monthEnd()"
        >
            <div
                class="flex w-[300%]"
                :class="monthAnimating ? 'transition-transform duration-300 ease-out' : ''"
                :style="`transform: translateX(${monthTranslateX}%);`"
            >
                @foreach ($this->carouselMonths as $carouselMonth)
                    @php
                        $carouselWeeks = $this->getWeeksForMonth($carouselMonth);
                    @endphp

                    <div
                        class="w-1/3 shrink-0"
                        wire:key="carousel-month-{{ $carouselMonth->format('Y-m') }}-{{ $activeFilter }}"
                    >
                        @foreach ($carouselWeeks as $weekIndex => $week)
                            @php
                                $trackHeight = 20;
                                $trackGap = 5;
                                $trackAreaHeight = max(count($week['tracks']), 1) * $trackHeight + max(count($week['tracks']) - 1, 0) * $trackGap;
                            @endphp
                            <div class="{{ $weekIndex > 0 ? 'border-t-[1px] border-zinc-300' : '' }} pt-[15px] pb-[15px] bg-[#D9D9D9]/10">
                                <div class="grid grid-cols-7 gap-0 mb-[15px]">
                                    @foreach ($week['days'] as $day)
                                        @php
                                            $isToday = $day['isToday'];
                                            $background = $isToday ? '#111111' : 'transparent';
                                            $color = $isToday ? '#FFFFFF' : ($day['inMonth'] ? '#000000' : '#8E8E8E');
                                        @endphp

                                        <button
                                            type="button"
                                            wire:click="openDay('{{ $day['date']->toDateString() }}')"
                                            class="flex min-h-[82px] justify-center rounded-[18px] px-[1px] py-[4px] active:scale-[0.98] md:min-h-0 md:rounded-none md:py-0"
                                        >
                                           @php
    $shift = $this->getDayShiftSummary($day['date']);
@endphp

<div class="flex flex-col items-center gap-[4px]">
    @if ($isToday)
        <span class="rounded-full bg-[#111111] px-[7px] py-[3px] text-[9px] font-semibold uppercase leading-none tracking-[.06em] text-white">
            Сегодня
        </span>
    @endif

    <div
        class="px-[5px] py-[5px] items-center rounded-full flex items-center justify-center text-[16px] leading-none transition duration-200"
        style="background: {{ $background }}; color: {{ $color }}; font-weight: {{ ($isToday || $day['inMonth']) ? '600' : '400' }}; box-shadow: {{ $isToday ? '0 0 0 4px rgba(0,0,0,.08)' : 'none' }};"
    >
        {{ $day['date']->day }}
    </div>

    @if ($day['inMonth'] && $shift['total'] > 0)
        <span
            class="rounded-full px-[6px] py-[3px] text-[10px] font-semibold leading-none"
            style="background: {{ $shift['bg'] }}; color: {{ $shift['text'] }};"
        >
            {{ $shift['working'] }}/{{ $shift['total'] }}
        </span>
    @endif

    @php
        $badges = $this->getDayEventBadges($day['date']);
    @endphp

    @if ($day['inMonth'] && count($badges))
        <div class="mt-[3px] flex max-w-[44px] flex-wrap justify-center gap-[2px] md:hidden">
            @foreach ($badges as $badge)
                <span
                    class="flex h-[18px] min-w-[18px] items-center justify-center rounded-full px-[4px] text-[10px] font-semibold leading-none"
                    style="background: {{ $badge['bg'] }}; color: {{ $badge['text'] }};"
                >
                    {{ $badge['emoji'] }}{{ $badge['count'] > 1 ? $badge['count'] : '' }}
                </span>
            @endforeach
        </div>
    @endif
</div>
                                        </button>
                                    @endforeach
                                </div>

                                <div class="relative hidden overflow-hidden md:block" style="height: {{ $trackAreaHeight }}px;">
                                    <div class="space-y-[5px] pointer-events-none">
                                        @forelse ($week['tracks'] as $track)
                                            <div class="relative h-[20px]">
                                                @foreach ($track as $event)
                                                    @php
                                                        $leftPercent = (($event['colStart'] - 1) / 7) * 100;
                                                        $widthPercent = (($event['colEnd'] - $event['colStart']) / 7) * 100;

                                                        $weekStartDate = $week['days'][0]['date']->copy()->startOfDay();
                                                        $weekEndDate = $week['days'][6]['date']->copy()->startOfDay();

                                                        $continuesFromPrevWeek = $event['start']->lt($weekStartDate);
                                                        $continuesToNextWeek = $event['end']->gt($weekEndDate);

                                                        $leftInset = $continuesFromPrevWeek ? 0 : 5;
                                                        $rightInset = $continuesToNextWeek ? 0 : 5;

                                                        $radiusStyle = match (true) {
                                                            $continuesFromPrevWeek && $continuesToNextWeek => 'border-radius:0;',
                                                            $continuesFromPrevWeek => 'border-top-left-radius:0;border-bottom-left-radius:0;border-top-right-radius:9999px;border-bottom-right-radius:9999px;',
                                                            $continuesToNextWeek => 'border-top-right-radius:0;border-bottom-right-radius:0;border-top-left-radius:9999px;border-bottom-left-radius:9999px;',
                                                            default => 'border-radius:9999px;',
                                                        };

                                                        $finalWidthPx = $leftInset + $rightInset;
                                                    @endphp

                                                    <div
                                                        class="absolute top-0 h-[20px] px-[5px] flex items-center overflow-hidden"
                                                        style="
                                                            left: calc({{ $leftPercent }}% + {{ $leftInset }}px);
                                                            width: calc({{ $widthPercent }}% - {{ $finalWidthPx }}px);
                                                            {{ $radiusStyle }}
                                                            {{ $event['style'] }}
                                                        "
                                                        title="{{ $event['title'] }}"
                                                    >
                                                        <span class="text-[12px] leading-none truncate">
                                                            {{ $event['short'] }}
                                                        </span>
                                                    </div>
                                                @endforeach
                                            </div>
                                        @empty
                                            <div class="h-[20px]"></div>
                                        @endforelse
                                    </div>

                                    <div class="absolute inset-0 grid grid-cols-7 z-[2]">
                                        @foreach ($week['days'] as $trackDay)
                                            <button
                                                type="button"
                                                wire:click="openDay('{{ $trackDay['date']->toDateString() }}')"
                                                class="w-full h-full"
                                            ></button>
                                        @endforeach
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endforeach
            </div>
      
        </div>

        @elseif ($calendarMode === 'shift')
            <div class="px-[14px] pb-[20px] space-y-[8px]">
                @foreach ($this->getPeopleDaysForMonth($month) as $shiftDay)
                    @php
                        $shift = $this->getDayShiftSummary($shiftDay);
                        $isToday = $shiftDay->isToday();
                    @endphp

                    <button
                        type="button"
                        wire:click="openDay('{{ $shiftDay->toDateString() }}')"
                        class="w-full rounded-[28px] px-[14px] py-[13px] text-left transition active:scale-[0.99]"
                        style="background: {{ $isToday ? '#111111' : $shift['bg'] }}; color: {{ $isToday ? '#FFFFFF' : '#111111' }};"
                    >
                        <div class="flex items-center justify-between gap-[12px]">
                            <div>
                                @if ($isToday)
                                    <p class="mb-[5px] text-[11px] font-semibold uppercase tracking-[.08em] text-white/70">Сегодня</p>
                                @endif

                                <p class="text-[17px] font-semibold leading-none">
                                    {{ $shiftDay->translatedFormat('j F · l') }}
                                </p>

                                <p class="mt-[7px] text-[13px] leading-none" style="color: {{ $isToday ? 'rgba(255,255,255,.75)' : $shift['text'] }};">
                                    {{ $shift['label'] }}
                                </p>
                            </div>

                            <div class="shrink-0 rounded-full px-[11px] py-[7px] text-[14px] font-bold" style="background: {{ $isToday ? 'rgba(255,255,255,.16)' : 'rgba(255,255,255,.72)' }}; color: {{ $isToday ? '#FFFFFF' : $shift['text'] }};">
                                {{ $shift['working'] }}/{{ $shift['total'] }}
                            </div>
                        </div>
                    </button>
                @endforeach
            </div>
        @elseif ($calendarMode === 'people')
            @php
                $peopleDays = $this->getPeopleDaysForMonth($month);
                $peopleRows = $this->getPeopleRowsForMonth($month);
            @endphp

            <div class="px-[14px] pb-[20px] space-y-[8px] md:hidden">
                @foreach ($peopleRows as $row)
                    @if ($row['bars']->count())
                        <div class="rounded-[26px] bg-[#F7F7F7] px-[14px] py-[12px]">
                            <p class="mb-[9px] text-[16px] font-semibold leading-none text-[#111]">
                                {{ $row['user']->name }}
                            </p>

                            <div class="space-y-[6px]">
                                @foreach ($row['bars'] as $bar)
                                    <button
                                        type="button"
                                        wire:click="openDay('{{ $bar['start']->toDateString() }}')"
                                        class="w-full rounded-[20px] px-[12px] py-[10px] text-left"
                                        style="{{ $bar['style'] }}"
                                    >
                                        <p class="text-[14px] font-semibold leading-none text-[#111]">
                                            {{ $bar['type'] === 'day_off' ? '🌿 Выходной' : '🏖️ Отпуск' }}
                                        </p>

                                        <p class="mt-[6px] text-[12px] leading-none text-black/60">
                                            {{ $this->formatEventRange($bar) }}
                                        </p>
                                    </button>
                                @endforeach
                            </div>
                        </div>
                    @endif
                @endforeach
            </div>

            <div class="hidden px-[10px] pb-[24px] overflow-x-auto no-scrollbar md:block">
                <div class="min-w-[1450px] rounded-[30px] bg-[#F7F7F7] p-[8px]">
                    <div class="flex items-center border-b border-black/10 pb-[8px]">
                        <div class="sticky left-0 z-20 w-[128px] shrink-0 rounded-[20px] bg-[#F7F7F7] px-[10px] py-[8px] text-[13px] font-semibold text-[#111]">
                            Клинер
                        </div>

                        <div class="flex">
                            @foreach ($peopleDays as $peopleDay)
                                @php $isToday = $peopleDay->isToday(); @endphp
                                <button
                                    type="button"
                                    wire:click="openDay('{{ $peopleDay->toDateString() }}')"
                                    class="mx-[2px] flex h-[42px] w-[38px] shrink-0 flex-col items-center justify-center rounded-[16px] text-[12px] font-semibold"
                                    style="background: {{ $isToday ? '#111111' : '#FFFFFF' }}; color: {{ $isToday ? '#FFFFFF' : '#111111' }};"
                                >
                                    @if ($isToday)
                                        <span class="text-[8px] uppercase leading-none text-white/70">сег</span>
                                    @endif
                                    <span class="leading-none">{{ $peopleDay->day }}</span>
                                </button>
                            @endforeach
                        </div>
                    </div>

                    <div class="divide-y divide-black/5">
                        @foreach ($peopleRows as $row)
                            <div class="relative flex min-h-[42px] items-center">
                                <button
                                    type="button"
                                    class="sticky left-0 z-20 flex h-[42px] w-[128px] shrink-0 items-center rounded-[18px] bg-[#F7F7F7] px-[10px] text-left"
                                >
                                    <span class="truncate text-[13px] font-medium text-[#111]">
                                        {{ $row['user']->name }}
                                    </span>
                                </button>

                                <div class="relative h-[42px]" style="width: {{ count($peopleDays) * 42 }}px;">
                                    <div class="absolute inset-0 flex">
                                        @foreach ($peopleDays as $peopleDay)
                                            <button
                                                type="button"
                                                wire:click="openDay('{{ $peopleDay->toDateString() }}')"
                                                class="mx-[2px] h-[42px] w-[38px] shrink-0 rounded-[14px] {{ $peopleDay->isToday() ? 'bg-black/[.06]' : 'bg-white' }}"
                                            ></button>
                                        @endforeach
                                    </div>

                                    @foreach ($row['bars'] as $bar)
                                        <button
                                            type="button"
                                            wire:click="openDay('{{ $bar['start']->toDateString() }}')"
                                            class="absolute top-[9px] z-10 h-[24px] overflow-hidden px-[8px] text-left text-[12px] font-medium leading-none"
                                            style="left: {{ $bar['left'] }}px; width: {{ $bar['width'] }}px; {{ $bar['style'] }} border-radius:9999px;"
                                            title="{{ $bar['title'] }}"
                                        >
                                            <span class="block truncate leading-[24px]">{{ $bar['short'] }}</span>
                                        </button>
                                    @endforeach
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        @endif
          
    </div>

<x-ui.bottom-sheet model="sheetOpen">
    <div class="h-[90vh] overflow-hidden rounded-[28px] flex flex-col">
        <div class="shrink-0 pt-[20px]">
            <div
                x-ref="weekViewport"
                class="overflow-hidden select-none mb-[5px]"
                @touchstart.passive="weekStart($event)"
                @touchmove.passive="weekMove($event)"
                @touchend.passive="weekEnd()"
            >
                <div
                    class="flex w-[300%]"
                    :class="weekAnimating ? 'transition-transform duration-300 ease-out' : ''"
                    :style="`transform: translateX(${weekTranslateX}%);`"
                >
                    @foreach ($this->sheetCarouselWeeks as $weekIndex => $sheetWeek)
                        <div
                            class="w-1/3 shrink-0"
                            wire:key="sheet-week-{{ $weekIndex }}-{{ $sheetWeek[0]->format('Y-m-d') }}-{{ $selectedDate }}"
                        >
                            <div class="grid grid-cols-7 gap-0">
                                @foreach ($sheetWeek as $index => $sheetDay)
                                    @php
                                        $isSheetSelected = $sheetDay->isSameDay($this->selectedDay);
                                        $isSheetToday = $sheetDay->isToday();
                                        $isCurrentMonth = $sheetDay->month === $this->selectedDay->month;

                                        $sheetBackground = 'transparent';
                                        $sheetColor = $isCurrentMonth ? '#000000' : '#9A9A9A';

                                        if ($isSheetToday) {
                                            $sheetBackground = '#111111';
                                            $sheetColor = '#FFFFFF';
                                        }

                                        if ($isSheetSelected && ! $isSheetToday) {
                                            $sheetBackground = '#FFFFFF';
                                            $sheetColor = '#111111';
                                        }
                                    @endphp

                                    <div class="flex flex-col items-center gap-[15px] py-[4px]">
                                        <span class="text-[14px] leading-none">
                                            {{ $this->dayLetters[$index] }}
                                        </span>

                                        <button
                                            type="button"
                                            wire:click="openDay('{{ $sheetDay->toDateString() }}')"
                                            class="w-[34px] h-[34px] rounded-full flex items-center justify-center text-[16px] leading-none transition-all duration-200"
                                            style="
                                                background: {{ $sheetBackground }};
                                                color: {{ $sheetColor }};
                                                font-weight: {{ ($isSheetSelected || $isSheetToday) ? '700' : '500' }};
                                                box-shadow: {{ $isSheetSelected && ! $isSheetToday ? '0 6px 18px rgba(0,0,0,.08)' : 'none' }};
                                            "
                                        >
                                            {{ $sheetDay->day }}
                                        </button>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>

        <div class="flex-1 min-h-0 overflow-y-auto overscroll-y-contain px-[14px] pb-[10px] pt-[10px]">
          @php
    $shift = $this->selectedDayShiftSummary;
    $staff = $this->selectedDayStaffSummary;
@endphp

            <div class="space-y-[10px]">
                <div class="rounded-[32px] px-[14px] py-[14px]" style="background: {{ $shift['bg'] }};">
                    <div class="flex items-start justify-between gap-[12px]">
                        <div>
                            @if ($this->selectedDay->isToday())
                                <p class="mb-[7px] text-[11px] font-semibold uppercase tracking-[.08em]" style="color: {{ $shift['text'] }};">
                                    Сегодня
                                </p>
                            @endif

                            <p class="text-[20px] font-semibold leading-none text-[#111]">
                                {{ $this->selectedDay->translatedFormat('j F · l') }}
                            </p>

                            <p class="mt-[8px] text-[14px] font-medium leading-none" style="color: {{ $shift['text'] }};">
                                {{ $shift['label'] }}
                            </p>
                        </div>

                        <div class="shrink-0 rounded-full bg-white/70 px-[12px] py-[8px] text-[14px] font-bold" style="color: {{ $shift['text'] }};">
                            {{ $shift['working'] }}/{{ $shift['total'] }}
                        </div>
                    </div>

            <div class="mt-[12px] grid grid-cols-3 gap-[6px]">
    <div class="rounded-[18px] bg-white/65 px-[10px] py-[9px]">
        <p class="text-[11px] leading-none text-[#777]">
            Всего
        </p>

        <p class="mt-[6px] text-[17px] font-semibold leading-none text-[#111]">
            {{ $staff['total'] }}
        </p>
    </div>

    <div class="rounded-[18px] bg-white/65 px-[10px] py-[9px]">
        <p class="text-[11px] leading-none text-[#777]">
            Супервайзеров
        </p>

        <p class="mt-[6px] text-[17px] font-semibold leading-none text-[#111]">
            {{ $staff['supervisors'] }}
        </p>
    </div>

    <div class="rounded-[18px] bg-white/65 px-[10px] py-[9px]">
        <p class="text-[11px] leading-none text-[#777]">
            Клинеров
        </p>

        <p class="mt-[6px] text-[17px] font-semibold leading-none text-[#111]">
            {{ $staff['cleaners'] }}
        </p>

        @if($staff['cleaners_not_working'] > 0)
            <p class="mt-[6px] text-[11px] leading-none text-[#9F1D1D]">
                сегодня нет {{ $staff['cleaners_not_working'] }}
            </p>
        @endif
    </div>
</div>
                </div>

                <div class="flex gap-[6px] rounded-full bg-[#F1F1F1] p-[4px]">
                @foreach ([
    'events' => 'События',
    'people' => 'Люди',
] as $tabKey => $tabLabel)
                        <button
                            type="button"
                            wire:click="setDaySheetTab('{{ $tabKey }}')"
                            class="flex-1 rounded-full px-[10px] py-[8px] text-[13px] font-semibold"
                            style="background: {{ $daySheetTab === $tabKey ? '#111111' : 'transparent' }}; color: {{ $daySheetTab === $tabKey ? '#FFFFFF' : '#111111' }};"
                        >
                            {{ $tabLabel }}
                        </button>
                    @endforeach
                </div>

               

                @if ($daySheetTab === 'events')
                    @forelse ($this->selectedDayEventGroups as $group)
                        <div class="rounded-[30px] bg-white px-[14px] py-[14px]">
                            <div class="mb-[10px] flex items-center justify-between">
                                <p class="text-[17px] font-semibold leading-none text-[#111]">
                                    {{ $group['emoji'] }} {{ $group['title'] }}
                                </p>

                                <span class="rounded-full bg-[#F1F1F1] px-[9px] py-[5px] text-[12px] text-[#777]">
                                    {{ $group['events']->count() }}
                                </span>
                            </div>

                            <div class="space-y-[7px]">
                                @foreach ($group['events'] as $event)
                                    @php
                                        $isTask = ($event['source'] ?? null) === 'task';
                                        $tag = $isTask ? 'a' : 'button';
                                    @endphp

                                    <{{ $tag }}
                                        @if($isTask)
                                            href="{{ $event['url'] }}"
                                        @else
                                            type="button"
                                        @endif
                                        class="block w-full rounded-[24px] px-[13px] py-[12px] text-left"
                                        style="{{ $event['style'] }}"
                                    >
                                        <p class="text-[12px] font-[600] leading-none text-black/65 mb-[6px]">
                                            {{ $this->formatEventRange($event) }}
                                        </p>

@if (filled($event['lane_title'] ?? null))
    <div class="mb-[8px] flex items-center gap-[7px]">
        <div class="flex h-[28px] w-[28px] shrink-0 items-center justify-center rounded-full bg-white/65 text-[12px] font-bold text-[#111]">
            {{ mb_substr($event['lane_title'], 0, 1) }}
        </div>

        <div class="min-w-0">
            <p class="truncate text-[15px] font-semibold leading-none text-[#111]">
                {{ $event['lane_title'] }}
            </p>

            <p class="mt-[4px] text-[12px] leading-none text-black/55">
                {{ match ($event['type'] ?? null) {
                    'vacation' => 'Отпуск',
                    'day_off' => 'Выходной',
                    'tasks' => 'Задача',
                    'holiday' => 'Праздник',
                    default => 'Событие',
                } }}
            </p>
        </div>
    </div>
@endif

@php
    $displayTitle = match ($event['type'] ?? null) {
        'vacation' => 'Отпуск',
        'day_off' => 'Выходной',
        default => $event['title'],
    };
@endphp

<p class="text-[15px] leading-[1.2] text-black/75">
    {{ $displayTitle }}
</p>

                                        @if (filled($event['description']))
                                            <p class="mt-[6px] text-[14px] leading-[1.25] text-black/75">
                                                {{ $event['description'] }}
                                            </p>
                                        @endif

                                        @if ($isTask)
                                            <div class="mt-[10px] flex flex-wrap gap-[6px]">
                                                <span class="rounded-full bg-white/55 px-[9px] py-[5px] text-[12px] leading-none">
                                                    {{ $event['meta']['status_label'] ?? 'Задача' }}
                                                </span>

                                                <span class="rounded-full bg-white/55 px-[9px] py-[5px] text-[12px] leading-none">
                                                    до {{ $event['meta']['deadline_time'] ?? '' }}
                                                </span>

                                                <span class="rounded-full bg-white/55 px-[9px] py-[5px] text-[12px] leading-none">
                                                    {{ $event['meta']['priority_label'] ?? 'Обычный' }}
                                                </span>
                                            </div>
                                        @endif
                                    </{{ $tag }}>
                                @endforeach
                            </div>
                        </div>
                    @empty
                        <div class="rounded-[30px] bg-white px-[15px] py-[15px]">
                            <p class="text-[16px] text-[#6F6F6F]">
                                На эту дату событий нет.
                            </p>
                        </div>
                    @endforelse
                @endif

                @if ($daySheetTab === 'people')
                    <div class="rounded-[32px] bg-white px-[14px] py-[14px]">

                        @if ($this->selectedDayWorkers['not_working']->count())
                            <div class="mb-[14px]">
                                <div class="mb-[8px] flex items-center justify-between">
                                    <p class="text-[16px] font-semibold leading-none text-[#111]">
                                        Кто не работает
                                    </p>

                                    <span class="rounded-full bg-[#F1F1F1] px-[9px] py-[5px] text-[12px] text-[#777]">
                                        {{ $this->selectedDayWorkers['not_working_count'] }}
                                    </span>
                                </div>

                                <div class="space-y-[6px]">
                                    @foreach ($this->selectedDayWorkers['not_working'] as $user)
                                        <div class="flex items-center gap-[10px] rounded-[22px] bg-[#FFF6F6] px-[10px] py-[9px]">
                                            <div class="flex h-[36px] w-[36px] shrink-0 items-center justify-center rounded-full bg-white text-[14px] font-semibold text-[#9F1D1D]">
                                                {{ mb_substr($user->name, 0, 1) }}
                                            </div>

                                            <div class="min-w-0 flex-1">
                                                <p class="truncate text-[15px] font-medium leading-none text-[#111]">
                                                    {{ $user->name }}
                                                </p>

                                                <p class="mt-[6px] text-[12px] leading-[1.2] text-[#9F1D1D]">
                                                 {{ $user->not_working_reason ?? 'Не работает' }}
                                                </p>
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @endif

                        <div>
                            <div class="mb-[8px] flex items-center justify-between">
                                <p class="text-[16px] font-semibold leading-none text-[#111]">
                                    Работают
                                </p>

                                <span class="rounded-full bg-[#E7F6EC] px-[9px] py-[5px] text-[12px] text-[#3C8D57]">
                                   {{ $this->selectedDayWorkers['working_count'] + $this->selectedDaySupervisors->count() }}
                                </span>
                            </div>

                            <div class="space-y-[6px]">
                                @foreach ($this->selectedDaySupervisors as $user)
    <div class="flex items-center gap-[10px] rounded-[22px] bg-[#F8F8F8] px-[10px] py-[9px]">
        <div class="flex h-[36px] w-[36px] shrink-0 items-center justify-center rounded-full bg-white text-[14px] font-semibold text-[#111]">
            {{ mb_substr($user->name, 0, 1) }}
        </div>

        <div class="min-w-0 flex-1">
            <p class="truncate text-[15px] font-medium leading-none text-[#111]">
                {{ $user->name }}
            </p>

            <p class="mt-[5px] text-[12px] leading-none text-[#8A8A8A]">
                Супервайзер
            </p>
        </div>

     
    </div>
@endforeach
                                @forelse ($this->selectedDayWorkers['working'] as $user)
                                    <div class="flex items-center gap-[10px] rounded-[22px] bg-[#F8F8F8] px-[10px] py-[9px]">
                                        <div class="flex h-[36px] w-[36px] shrink-0 items-center justify-center rounded-full bg-white text-[14px] font-semibold text-[#111]">
                                            {{ mb_substr($user->name, 0, 1) }}
                                        </div>

                                        <div class="min-w-0 flex-1">
                                            <p class="truncate text-[15px] font-medium leading-none text-[#111]">
                                                {{ $user->name }}
                                            </p>

                                            <p class="mt-[5px] text-[12px] leading-none text-[#8A8A8A]">
                                                Клинер
                                            </p>
                                        </div>

                                    </div>
                                @empty
                                    <div class="rounded-[22px] bg-[#FFF1C7] px-[12px] py-[12px]">
                                        <p class="text-[14px] text-[#8A6500]">
                                            На эту дату нет работающих клинеров.
                                        </p>
                                    </div>
                                @endforelse
                            </div>
                        </div>
                    </div>
                @endif
            </div>
        </div>
    </div>
</x-ui.bottom-sheet>

    <x-slot:navbarTop>
        <div class="flex items-center gap-[8px] overflow-x-auto no-scrollbar">
            <button
                type="button"
                class="h-[40px] shrink-0 rounded-full bg-[#111111] px-[16px] text-[14px] font-medium text-white"
            >
                Создать
            </button>

            <button
                type="button"
                class="h-[40px] shrink-0 rounded-full bg-[#F3F3F3] px-[16px] text-[14px] font-medium text-[#111111]"
            >
                Фильтр
            </button>
        </div>
    </x-slot:navbarTop>
</div>