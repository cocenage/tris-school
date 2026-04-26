<?php

use App\Models\CalendarEvent;
use App\Models\DayOffRequestDay;
use App\Models\User;
use App\Models\VacationRequest;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Livewire\Component;

new class extends Component {
    public Carbon $month;
    public ?string $selectedDate = null;
    public bool $sheetOpen = false;
    public string $activeFilter = 'all';

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

    public function setFilter(string $filter): void
    {
        $this->activeFilter = $filter;
    }

    public function openDay(string $date): void
    {
        $this->selectedDate = $date;
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
        return [
            'all' => 'Все',
            'workflow' => 'Рабочие процессы',
            'finance' => 'Финансы',
            'holiday' => 'Праздники',
            'peak' => 'Пики загрузки',
            'vacation' => 'Выходные и отпуска',
            'strike' => 'Забастовки',
        ];
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
                        fn(array $event) => $day->betweenIncluded($event['start'], $event['end'])
                    )->count(),
                ];
            }

            $weekEvents = $events
                ->filter(fn(array $event) => $event['start']->lte($weekEnd) && $event['end']->gte($weekStart))
                ->sortBy([
                    ['priority', 'desc'],
                    ['start', 'asc'],
                ])
                ->values();

            $tracks = [];

            foreach ($weekEvents as $event) {
                $visibleStart = $event['start']->lt($weekStart) ? $weekStart : $event['start'];
                $visibleEnd = $event['end']->gt($weekEnd) ? $weekEnd : $event['end'];

                $colStart = $weekStart->diffInDays($visibleStart) + 1;
                $colEnd = $weekStart->diffInDays($visibleEnd) + 2;

                $placed = false;

                foreach ($tracks as $trackIndex => $trackItems) {
                    $hasCollision = collect($trackItems)->contains(function ($item) use ($colStart, $colEnd) {
                        return ! ($colEnd <= $item['colStart'] || $colStart >= $item['colEnd']);
                    });

                    if (! $hasCollision) {
                        $tracks[$trackIndex][] = [
                            ...$event,
                            'colStart' => $colStart,
                            'colEnd' => $colEnd,
                        ];
                        $placed = true;
                        break;
                    }
                }

                if (! $placed) {
                    $tracks[] = [[
                        ...$event,
                        'colStart' => $colStart,
                        'colEnd' => $colEnd,
                    ]];
                }
            }

            $weeks[] = [
                'days' => $days,
                'tracks' => $tracks,
            ];

            $cursor->addWeek();
        }

        return $weeks;
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
                        fn(array $event) => $day->betweenIncluded($event['start'], $event['end'])
                    )->count(),
                ];
            }

            $weekEvents = $events
                ->filter(fn(array $event) => $event['start']->lte($weekEnd) && $event['end']->gte($weekStart))
                ->sortBy([
                    ['priority', 'desc'],
                    ['start', 'asc'],
                ])
                ->values();

            $tracks = [];

            foreach ($weekEvents as $event) {
                $visibleStart = $event['start']->lt($weekStart) ? $weekStart : $event['start'];
                $visibleEnd = $event['end']->gt($weekEnd) ? $weekEnd : $event['end'];

                $colStart = $weekStart->diffInDays($visibleStart) + 1;
                $colEnd = $weekStart->diffInDays($visibleEnd) + 2;

                $placed = false;

                foreach ($tracks as $trackIndex => $trackItems) {
                    $hasCollision = collect($trackItems)->contains(function ($item) use ($colStart, $colEnd) {
                        return ! ($colEnd <= $item['colStart'] || $colStart >= $item['colEnd']);
                    });

                    if (! $hasCollision) {
                        $tracks[$trackIndex][] = [
                            ...$event,
                            'colStart' => $colStart,
                            'colEnd' => $colEnd,
                        ];
                        $placed = true;
                        break;
                    }
                }

                if (! $placed) {
                    $tracks[] = [[
                        ...$event,
                        'colStart' => $colStart,
                        'colEnd' => $colEnd,
                    ]];
                }
            }

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

    protected function formatUserDip(?User $user): string
    {
        if (! $user) {
            return '';
        }

        return $user->dip ? ' • DIP' : ' • NO DIP';
    }

    protected function getExpandedEvents(Carbon $rangeStart, Carbon $rangeEnd): Collection
    {
        $baseEvents = CalendarEvent::query()
            // ->with('user')
            ->where('is_active', true)
            ->orderByDesc('priority')
            ->orderBy('start_date')
            ->get();

        $expanded = collect();

        foreach ($baseEvents as $event) {
            $expanded = $expanded->merge(
                $this->expandEventOccurrences($event, $rangeStart->copy(), $rangeEnd->copy())
            );
        }

        $users = User::query()
            ->where('is_active', true)
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

                        $expanded->push([
                            'id' => 'work_' . $user->id . '_' . $year,
                            'title' => "{$user->name}{$this->formatUserDip($user)} — {$years} лет в компании",
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

$dayOffDays = DayOffRequestDay::query()
    ->with(['user', 'request'])
    ->whereBetween('date', [
        $rangeStart->copy()->toDateString(),
        $rangeEnd->copy()->toDateString(),
    ])
    ->whereHas('request', function ($query) {
    $query->where('status', 'approved');
})
    ->get();

foreach ($dayOffDays as $dayOffDay) {
    $user = $dayOffDay->user;
    $date = Carbon::parse($dayOffDay->date)->startOfDay();

    $statusLabel = match ($dayOffDay->status) {
        'approved' => 'Выходной',
        'rejected' => 'Выходной отклонён',
        default => 'Выходной',
    };

    $expanded->push([
        'id' => 'day_off_' . $dayOffDay->id,
        'title' => "{$user?->name}{$this->formatUserDip($user)} — {$statusLabel}",
        'description' => $dayOffDay->request?->reason,
        'short' => mb_strimwidth("🌿 " . ($user?->name ?? 'Выходной'), 0, 12, '...'),
        'type' => 'vacation',
        'priority' => 85,
        'start' => $date->copy(),
        'end' => $date->copy(),
        'style' => $this->eventStyle('vacation'),
    ]);
}

$vacationRequests = VacationRequest::query()
    ->with(['user', 'days'])
    ->where('status', 'approved')
    ->whereHas('days', function ($query) use ($rangeStart, $rangeEnd) {
        $query->whereBetween('date', [
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

    $statusLabel = match ($vacationRequest->status) {
        'approved' => 'Отпуск',
        'partially_approved' => 'Отпуск частично одобрен',
        default => 'Отпуск',
    };

    $expanded->push([
        'id' => 'vacation_' . $vacationRequest->id . '_' . $start->format('Ymd'),
        'title' => "{$user?->name}{$this->formatUserDip($user)} — {$statusLabel}",
        'description' => $vacationRequest->reason,
        'short' => mb_strimwidth("🏖 " . ($user?->name ?? 'Отпуск'), 0, 12, '...'),
        'type' => 'vacation',
        'priority' => 80,
        'start' => $start,
        'end' => $end,
        'style' => $this->eventStyle('vacation'),
    ]);
}

        if ($this->activeFilter !== 'all') {
            $expanded = $expanded
                ->filter(fn(array $event) => $event['type'] === $this->activeFilter)
                ->values();
        }

        return $expanded
            ->sortByDesc('priority')
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
        $user = $event->user;

        $title = $event->title;

        if ($user) {
            $title .= ' — ' . $user->name . $this->formatUserDip($user);
        }

        return [
            'id' => $event->id . '_' . $start->format('Ymd'),
            'title' => $title,
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
            'finance' => 'background:#CBEED9;color:#111111;',
            'holiday' => 'background:#F3B8B8;color:#111111;',
            'peak' => 'background:#F3E69C;color:#111111;',
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
            class="flex h-[40px] min-w-[40px] items-center justify-center rounded-full group cursor-pointer
                   bg-[#E1E1E1] backdrop-blur-md
                   text-white
                   transition-all duration-300
                   hover:bg-[#7D7D7D]"
        >
            <x-heroicon-o-arrow-left
                class="h-[20px] w-[20px] stroke-[2.4] group-active:scale-[0.95]"
            />
        </button>
        <span class="flex items-center justify-center text-[18px] leading-none">
            Календарь
        </span>

     <button
            type="button"
     
            class="flex h-[40px] min-w-[40px] items-center justify-center rounded-full group cursor-pointer
                   bg-[#E1E1E1] backdrop-blur-md
                   text-white
                   transition-all duration-300
                   hover:bg-[#7D7D7D]"
        >
            <x-heroicon-o-magnifying-glass
                class="h-[20px] w-[20px] stroke-[2.4] group-active:scale-[0.95]"
            />
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

                            <div class="{{ $weekIndex > 0 ? 'border-t-[1px] border-black' : '' }} pt-[15px] pb-[15px] bg-[#D9D9D9]/10">
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
                                            class="flex justify-center"
                                        >
                                            <div class="flex flex-col items-center gap-[5px]">
                                                <div
                                                    class="px-[5px] py-[5px] items-center rounded-full flex items-center justify-center text-[16px] leading-none transition duration-200"
                                                    style="background: {{ $background }}; color: {{ $color }}; font-weight: {{ ($isToday || $day['inMonth']) ? '600' : '400' }};"
                                                >
                                                    {{ $day['date']->day }}
                                                </div>
                                            </div>
                                        </button>
                                    @endforeach
                                </div>

                                <div class="relative overflow-hidden" style="height: {{ $trackAreaHeight }}px;">
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
            <span class="text-[32px] p-[20px]">
            {{ $month->translatedFormat('F') }}
        </span>
        </div>
          
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
            <div class="space-y-[8px]">
                @forelse ($this->selectedDayEvents as $event)
                    <button
                        type="button"
                        class="w-full rounded-[30px] px-[15px] py-[15px] text-left"
                        style="{{ $event['style'] }}"
                    >
                        <p class="text-[12px] font-[500] leading-none text-black/65 mb-[6px]">
                            {{ $this->formatEventRange($event) }}
                        </p>

                        <p class="text-[16px] leading-[1.1] mb-[4px]">
                            {{ $event['title'] }}
                        </p>

                        @if (filled($event['description']))
                            <p class="text-[14px] leading-[1.25] text-black">
                                {{ $event['description'] }}
                            </p>
                        @endif
                    </button>
                @empty
                    <div class="rounded-[30px] bg-white px-[15px] py-[15px]">
                        <p class="text-[16px] text-[#6F6F6F]">
                            На эту дату событий нет
                        </p>
                    </div>
                @endforelse
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