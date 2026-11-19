<?php

use App\Models\User;
use App\Services\Calendar\EmployeeWorkCalendarManagementService;
use App\Services\Calendar\EmployeeWorkCalendarService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component
{
    public string $month = '';

    public string $selectedDate = '';

    #[Locked]
    public ?int $employeeId = null;

    public function mount(?int $employee = null): void
    {
        $this->employeeId = $employee;

        $today = now(config('app.timezone', 'Europe/Rome'));
        $this->month = $today->format('Y-m');
        $this->selectedDate = $today->toDateString();

        $requestedMonth = request()->query('month');
        if ($employee === null && is_string($requestedMonth)) {
            $this->month = $requestedMonth;
            $month = $this->validMonth();
            $this->selectedDate = $month->isSameMonth($today) ? $today->toDateString() : $month->toDateString();
            $day = request()->query('day');

            if (is_string($day) && str_starts_with($day, $month->format('Y-m').'-')
                && validator(['day' => $day], ['day' => ['date_format:Y-m-d']])->passes()) {
                $this->selectedDate = $day;
            }
        }
    }

    public function previousMonth(): void
    {
        $this->shiftMonth(-1);
    }

    public function nextMonth(): void
    {
        $this->shiftMonth(1);
    }

    protected function shiftMonth(int $direction): void
    {
        $current = $this->validMonth();
        $next = $current->copy()->addMonthsNoOverflow($direction);
        $this->month = $next->format('Y-m');

        if (! $this->calendar['days']->contains(fn (array $day) => $day['date'] === $this->selectedDate)) {
            $this->selectedDate = $next->toDateString();
        }
    }

    public function selectDate(string $date): void
    {
        $validated = validator(['date' => $date], ['date' => ['required', 'date_format:Y-m-d']])->validate()['date'];
        $selected = Carbon::parse($validated, config('app.timezone', 'Europe/Rome'));
        $calendar = $this->calendar;

        abort_unless(collect($calendar['days'])->contains(fn (array $day) => $day['date'] === $selected->toDateString()), 403);

        $this->selectedDate = $selected->toDateString();
    }

    public function markTodayNoWork(): void
    {
        abort_unless($this->employeeId !== null, 403);
        abort_unless($this->selectedDate === Carbon::now('Europe/Rome')->toDateString(), 403);

        $actor = auth()->user();
        abort_unless($actor, 403);

        app(EmployeeWorkCalendarManagementService::class)->markNoWorkToday($this->employee, $actor);
    }

    protected function validMonth(): Carbon
    {
        try {
            $month = Carbon::createFromFormat('!Y-m', $this->month, config('app.timezone', 'Europe/Rome'));
        } catch (Throwable) {
            $month = null;
        }

        if (! $month || $month->format('Y-m') !== $this->month || (int) $month->format('Y') < 2000 || (int) $month->format('Y') > 2100) {
            $month = now(config('app.timezone', 'Europe/Rome'))->startOfMonth();
            $this->month = $month->format('Y-m');
        }

        return $month->startOfMonth();
    }

    #[Computed]
    public function employee(): User
    {
        if ($this->employeeId !== null) {
            Gate::authorize('view-employee-calendars');

            return User::query()->activeStaff()
                ->whereIn('role', ['cleaner', 'supervisor'])
                ->findOrFail($this->employeeId);
        }

        $user = auth()->user();
        abort_unless($user?->isApproved() && in_array($user->role, ['cleaner', 'supervisor'], true), 403);

        return $user;
    }

    #[Computed]
    public function calendar(): array
    {
        Carbon::setLocale('ru');

        $month = $this->validMonth();
        $start = $month->copy()->startOfWeek(Carbon::MONDAY);
        $end = $month->copy()->endOfMonth()->endOfWeek(Carbon::SUNDAY);
        $projection = app(EmployeeWorkCalendarService::class)->forEmployee($this->employee, $start, $end);
        $now = now('Europe/Rome');
        $today = $now->toDateString();
        $lastCompletedDay = $now->hour >= 19 ? $today : $now->copy()->subDay()->toDateString();
        $days = collect();

        for ($cursor = $start->copy(); $cursor->lte($end); $cursor->addDay()) {
            $date = $cursor->toDateString();
            $record = $projection->get($date);
            $state = $record['state'] ?? 'unknown_schedule';
            $future = $date > $today;

            $days->push([
                'date' => $date,
                'day' => (int) $cursor->day,
                'in_month' => $cursor->month === $month->month,
                'today' => $date === $today,
                'state' => $future ? 'future_unassigned' : ($state === 'planned_work' && $date <= $lastCompletedDay ? 'completed_workday' : $state),
                'actual_state' => $state,
                'future' => $future,
                'pending' => (bool) ($record['pending'] ?? false),
                'sources' => $record['sources'] ?? [],
            ]);
        }

        return [
            'month' => $month,
            'label' => $month->translatedFormat('F Y'),
            'days' => $days,
            'weeks' => $days->chunk(7),
        ];
    }

    public function getSelectedDayProperty(): ?array
    {
        return collect($this->calendar['days'])->firstWhere('date', $this->selectedDate);
    }

    protected function stateLabel(string $state): string
    {
        return match ($state) {
            'no_work' => 'Работа не назначена',
            'future_unassigned' => 'Пока не назначено',
            'completed_workday' => 'Рабочий день завершён по графику',
            'planned_work' => 'Рабочий день по графику',
            'regular_day_off' => 'Регулярный выходной',
            'approved_absence' => 'Одобрено отсутствие',
            'not_employed' => 'До даты начала работы',
            default => 'График не зафиксирован',
        };
    }

    protected function stateClass(string $state): string
    {
        return match ($state) {
            'no_work', 'future_unassigned' => 'bg-[#E6E8EB] text-[#59616B]',
            'completed_workday' => 'bg-[#BCE8C7] text-[#205734]',
            'planned_work' => 'bg-[#E7F6EC] text-[#27683C]',
            'regular_day_off' => 'bg-[#FCE8E8] text-[#A72B2B]',
            'approved_absence' => 'bg-[#FFF2CC] text-[#805B00]',
            'not_employed' => 'bg-white text-black/35',
            default => 'bg-[#F1F2F4] text-[#666B73]',
        };
    }
};
?>

<x-slot:header>
    <div class="flex h-[70px] items-center gap-3 px-4 text-[#213259]">
        <a href="{{ route($employeeId === null ? 'page-profile' : 'page-profile.employee-calendars') }}" aria-label="Назад" class="p-2">
            <x-heroicon-o-arrow-left class="size-5" />
        </a>
        <span class="min-w-0 truncate text-[18px] leading-none">{{ $employeeId === null ? 'Мой календарь' : $this->employee->name }}</span>
    </div>
</x-slot:header>

<main wire:poll.60s.visible class="min-h-[calc(100dvh-70px)] bg-white px-4 pb-8 pt-3">
    <section aria-label="Календарь сотрудника" class="rounded-[28px] bg-[#F8F7F5] p-3 sm:p-4">
        <div class="mb-4 flex items-center justify-between gap-3">
            <button type="button" wire:click="previousMonth" aria-label="Предыдущий месяц" class="flex size-10 items-center justify-center rounded-full bg-white text-[#213259]">
                <x-heroicon-o-chevron-left class="size-5" />
            </button>
            <h1 class="text-center text-[18px] font-semibold capitalize text-[#111]">{{ $this->calendar['label'] }}</h1>
            <button type="button" wire:click="nextMonth" aria-label="Следующий месяц" class="flex size-10 items-center justify-center rounded-full bg-white text-[#213259]">
                <x-heroicon-o-chevron-right class="size-5" />
            </button>
        </div>

        <div class="mb-2 grid grid-cols-7 gap-1 text-center text-[11px] font-medium text-black/45" aria-hidden="true">
            @foreach (['Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс'] as $weekday)
                <span class="py-1">{{ $weekday }}</span>
            @endforeach
        </div>

        <div class="space-y-1">
            @foreach ($this->calendar['weeks'] as $week)
                <div class="grid grid-cols-7 gap-1">
                    @foreach ($week as $day)
                        @php
                            $stateClass = $this->stateClass($day['state']);
                            $selected = $this->selectedDate === $day['date'];
                        @endphp
                        <button
                            type="button"
                            wire:key="calendar-day-{{ $day['date'] }}"
                            wire:click="selectDate('{{ $day['date'] }}')"
                            aria-label="{{ $day['date'] }} — {{ $this->stateLabel($day['state']) }}{{ $day['pending'] ? ', заявка ожидает решения' : '' }}"
                            aria-pressed="{{ $selected ? 'true' : 'false' }}"
                            @if ($day['today']) aria-current="date" @endif
                            @class([
                                'relative flex aspect-square min-w-0 items-center justify-center rounded-[14px] text-[14px]',
                                $stateClass,
                                'opacity-35' => ! $day['in_month'],
                                'ring-2 ring-[#213259] ring-offset-1' => $selected,
                                'ring-1 ring-[#2D6494] ring-inset' => $day['today'] && ! $selected,
                                'font-bold' => $day['today'],
                                'font-medium' => ! $day['today'],
                            ])
                        >
                            {{ $day['day'] }}
                            @if ($day['pending'])
                                <span class="absolute bottom-[4px] h-[4px] w-[4px] rounded-full bg-[#2D6494]" aria-hidden="true"></span>
                            @endif
                        </button>
                    @endforeach
                </div>
            @endforeach
        </div>
    </section>

    <section aria-label="Обозначения" class="mt-4 grid grid-cols-2 gap-x-3 gap-y-2 text-[12px] text-black/65">
        <div class="flex items-center gap-2"><span class="size-3 rounded-full bg-[#E6E8EB]"></span>Работа не назначена</div>
        @if($this->calendar['days']->contains('future', true))
            <div class="flex items-center gap-2"><span class="size-3 rounded-full bg-[#E6E8EB]"></span>Пока не назначено</div>
        @endif
        <div class="flex items-center gap-2"><span class="size-3 rounded-full bg-[#BCE8C7]"></span>Рабочий день завершён по графику</div>
        <div class="flex items-center gap-2"><span class="size-3 rounded-full bg-[#E7F6EC]"></span>Плановый рабочий день</div>
        <div class="flex items-center gap-2"><span class="size-3 rounded-full bg-[#E88D8D]"></span>Регулярный выходной</div>
        <div class="flex items-center gap-2"><span class="size-3 rounded-full bg-[#F2CD63]"></span>Одобренное отсутствие</div>
        @if($this->calendar['days']->contains('actual_state', 'unknown_schedule'))
            <div class="flex items-center gap-2"><span class="size-3 rounded-full bg-[#B9BDC4]"></span>График не зафиксирован</div>
        @endif
        <div class="col-span-2 flex items-center gap-2"><span class="size-2 rounded-full bg-[#2D6494]"></span>Заявка ожидает решения; цвет дня не меняется</div>
    </section>

    @if ($this->selectedDay)
        <section aria-live="polite" class="mt-5 rounded-[24px] border border-black/5 bg-white p-4 shadow-sm">
            <h2 class="text-[16px] font-semibold text-[#111]">{{ \Carbon\Carbon::parse($this->selectedDay['date'])->translatedFormat('j F Y') }}</h2>
            <p class="mt-1 text-[14px] {{ str_contains($this->stateClass($this->selectedDay['state']), 'text-[#A72B2B]') ? 'text-[#A72B2B]' : 'text-black/65' }}">
                {{ $this->stateLabel($this->selectedDay['state']) }}
            </p>
            @if ($this->selectedDay['pending'])
                <p class="mt-2 text-[13px] text-[#2D6494]">Есть заявка, ожидающая решения. Текущий статус дня сохранён.</p>
            @endif

            @if ($employeeId !== null
                && $this->selectedDay['date'] === \Carbon\Carbon::now('Europe/Rome')->toDateString()
                && $this->selectedDay['actual_state'] === 'planned_work'
                && auth()->user()?->is_active
                && in_array(auth()->user()?->role, ['admin', 'supervisor'], true))
                <button type="button" wire:click="markTodayNoWork" wire:loading.attr="disabled"
                    class="mt-4 inline-flex min-h-11 items-center justify-center rounded-full bg-[#E6E8EB] px-4 text-sm font-semibold text-[#39414B] hover:bg-[#D8DCE1] disabled:opacity-50">
                    Отметить «Работа не назначена»
                </button>
                @error('no_work')<p class="mt-2 text-sm text-[#A72B2B]">{{ $message }}</p>@enderror
            @endif

            @if (! empty($this->selectedDay['sources']))
                <ul class="mt-3 space-y-2 border-t border-black/5 pt-3 text-[13px] text-black/65">
                    @foreach ($this->selectedDay['sources'] as $source)
                        <li wire:key="calendar-source-{{ $source['type'] }}-{{ $source['id'] ?? $source['request_day_id'] ?? 'entry' }}">
                            @if ($source['type'] === 'manual_adjustment')
                                {{ $source['reason'] }}
                                @if ($source['actor_name'])<span class="text-black/45"> · {{ $source['actor_name'] }}</span>@endif
                            @else
                                {{ $source['type'] === 'vacation' ? 'Отпуск' : 'Заявка на выходной' }} — {{ $source['status'] }}
                                @if ($source['reviewer_name'])<span class="text-black/45"> · {{ $source['reviewer_name'] }}</span>@endif
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    @endif
</main>
