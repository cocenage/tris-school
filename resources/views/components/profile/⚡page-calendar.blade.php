<?php

use App\Services\Calendar\EmployeeWorkCalendarService;
use Carbon\Carbon;
use Livewire\Component;

new class extends Component
{
    public string $month = '';

    public string $selectedDate = '';

    public function mount(): void
    {
        abort_unless(in_array(auth()->user()->role, ['cleaner', 'supervisor'], true), 403);

        $this->month = now(config('app.timezone', 'Europe/Rome'))->startOfMonth()->format('Y-m');
        $this->selectedDate = now(config('app.timezone', 'Europe/Rome'))->toDateString();
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

    public function getCalendarProperty(): array
    {
        Carbon::setLocale('ru');

        $month = $this->validMonth();
        $start = $month->copy()->startOfWeek(Carbon::MONDAY);
        $end = $month->copy()->endOfMonth()->endOfWeek(Carbon::SUNDAY);
        $projection = app(EmployeeWorkCalendarService::class)->forEmployee(auth()->user(), $start, $end);
        $days = collect();

        for ($cursor = $start->copy(); $cursor->lte($end); $cursor->addDay()) {
            $date = $cursor->toDateString();
            $record = $projection->get($date);

            $days->push([
                'date' => $date,
                'day' => (int) $cursor->day,
                'in_month' => $cursor->month === $month->month,
                'today' => $cursor->isToday(),
                'state' => $record['state'] ?? 'unknown_schedule',
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
        <a href="{{ route('page-profile') }}" aria-label="Назад" class="p-2">
            <x-heroicon-o-arrow-left class="size-5" />
        </a>
        <span class="text-[18px] leading-none">Мой календарь</span>
    </div>
</x-slot:header>

<main class="min-h-[calc(100dvh-70px)] bg-white px-4 pb-8 pt-3">
    <p class="mb-4 text-[14px] leading-[1.45] text-black/60">
        Плановые рабочие дни и подтверждённые отсутствия. Календарь не отмечает фактическое присутствие.
    </p>

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
                            class="relative flex aspect-square min-w-0 items-center justify-center rounded-[14px] text-[14px] font-medium {{ $stateClass }} {{ $day['in_month'] ? '' : 'opacity-35' }} {{ $selected ? 'ring-2 ring-[#213259] ring-offset-1' : '' }} {{ $day['today'] ? 'font-bold' : '' }}"
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
        <div class="flex items-center gap-2"><span class="size-3 rounded-full bg-[#72C78B]"></span>Рабочий день</div>
        <div class="flex items-center gap-2"><span class="size-3 rounded-full bg-[#E88D8D]"></span>Регулярный выходной</div>
        <div class="flex items-center gap-2"><span class="size-3 rounded-full bg-[#F2CD63]"></span>Одобренное отсутствие</div>
        <div class="flex items-center gap-2"><span class="size-3 rounded-full bg-[#B9BDC4]"></span>График не зафиксирован</div>
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
