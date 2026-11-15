<?php

use App\Services\Calendar\EmployeeWorkCalendarStatisticsService;
use App\Services\EmployeeProfileStatistics;
use App\Services\Learning\LearningCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

new class extends Component
{
    private const DAY_TYPES = [
        'planned_workdays' => ['Рабочий день по графику', 'Рабочие дни по графику', '•', 'bg-[#E3EFF9] text-[#224C71]'],
        'regular_off_days' => ['Регулярный выходной', 'Выходные', '–', 'bg-[#F0F2F5] text-[#536477]'],
        'approved_day_off_days' => ['Одобренный отгул', 'Отгулы', '◇', 'bg-[#DFEEF3] text-[#235A6B]'],
        'approved_vacation_days' => ['Одобренный отпуск', 'Отпуск', '○', 'bg-[#EDE5F7] text-[#543973]'],
        'approved_unpaid_leave_days' => ['Неоплачиваемый отпуск', 'Неоплачиваемый отпуск', '△', 'bg-[#F9E8DB] text-[#754607]'],
        'other_approved_absence_days' => ['Одобренное отсутствие', 'Прочие отсутствия', '□', 'bg-[#E9EDF2] text-[#405064]'],
        'unknown_schedule_days' => ['График не зафиксирован', 'График не зафиксирован', '?', 'border border-dashed border-[#98A4AF] bg-white text-[#536477]'],
        'not_employed_days' => ['До начала работы', 'До начала работы', '–', 'bg-white text-[#596A7B]'],
    ];

    private const QUALITY_TYPES = [
        'red' => ['Красная зона', '!', 'bg-[#FAE3E6] text-[#822E40]'],
        'yellow' => ['Жёлтая зона', '△', 'bg-[#FFEFD6] text-[#754607]'],
        'green' => ['Зелёная зона', '✓', 'bg-[#DFF1E7] text-[#245E40]'],
        '' => ['Без зоны', '•', 'bg-[#E3EFF9] text-[#224C71]'],
    ];

    #[Url(as: 'stats_month')]
    public string $month = '';

    #[Url(as: 'stats_view')]
    public string $mode = 'schedule';

    #[Url(as: 'stats_day')]
    public string $selectedDay = '';

    public function boot(): void
    {
        abort_unless(EmployeeProfileStatistics::canView(auth()->user()), 403);
    }

    public function mount(): void
    {
        if ($this->month === '') {
            $this->month = EmployeeProfileStatistics::today()->format('Y-m');
        }
    }

    public function changeMonth(int $direction): void
    {
        abort_unless(in_array($direction, [-1, 1], true), 422);
        $next = $this->period->addMonthsNoOverflow($direction);
        if ($next->year >= 2000 && $next->year <= 2100) {
            $this->month = $next->format('Y-m');
            $this->selectedDay = '';
            unset($this->period, $this->dates, $this->statistics);
        }
    }

    public function chooseMode(string $mode): void
    {
        abort_unless(in_array($mode, ['schedule', 'quality'], true), 422);
        $this->mode = $mode;

        if ($mode === 'quality' && ! $this->statistics['activity']->has($this->selectedDay)) {
            $this->selectedDay = $this->statistics['activity']->keys()->first() ?? '';
        }
    }

    public function selectDay(string $day): void
    {
        abort_unless(in_array($day, $this->dates, true), 422);
        $this->selectedDay = $day;
    }

    public function showUpcomingWorkday(): void
    {
        $date = $this->statistics['next_workday'];
        if ($date === null) {
            return;
        }

        $this->mode = 'schedule';
        $this->month = substr($date, 0, 7);
        $this->selectedDay = $date;
        unset($this->period, $this->dates, $this->statistics);
        $this->dispatch('profile-upcoming-day-selected');
    }

    #[Computed]
    public function period(): CarbonImmutable
    {
        if (preg_match('/^(20[0-9]{2}|2100)-(0[1-9]|1[0-2])$/D', $this->month)) {
            return CarbonImmutable::createFromFormat('!Y-m', $this->month, config('app.timezone', 'Europe/Rome'));
        }

        return EmployeeProfileStatistics::today()->startOfMonth();
    }

    #[Computed]
    public function dates(): array
    {
        return array_map(fn (int $day): string => $this->period->setDay($day)->toDateString(), range(1, $this->period->daysInMonth));
    }

    #[Computed]
    public function statistics(): array
    {
        return app(EmployeeProfileStatistics::class)->forMonth(auth()->user(), $this->period);
    }

    protected function dateLabel(string $date, string $format = 'j F'): string
    {
        return CarbonImmutable::parse($date, config('app.timezone', 'Europe/Rome'))->locale('ru')->translatedFormat($format);
    }

    protected function dayTypes(): array
    {
        return self::DAY_TYPES;
    }

    protected function dayType(?array $day): array
    {
        return $day ? self::DAY_TYPES[app(EmployeeWorkCalendarStatisticsService::class)->metricForDay($day)] : self::DAY_TYPES['unknown_schedule_days'];
    }

    protected function qualityTypes(): array
    {
        return self::QUALITY_TYPES;
    }

    protected function qualityDay(Collection $entries): array
    {
        $controls = $entries->where('item_type', 'control');
        $hasCoaching = $entries->contains('item_type', 'coaching');

        if ($controls->isEmpty()) {
            return $hasCoaching
                ? ['Коучинг', '◇', 'bg-[#E7EDF8] text-[#394B78]']
                : ['Без записей', '', 'bg-[#F2F5F8] text-[#536477]'];
        }

        $zones = $controls->pluck('result_zone')->map(fn ($value) => (string) $value)->all();
        $worst = array_key_first(array_intersect_key(self::QUALITY_TYPES, array_flip($zones))) ?? '';
        [$label, $symbol, $tone] = self::QUALITY_TYPES[$worst];

        return [($hasCoaching ? 'Контроль и коучинг' : 'Контроль').' · '.$label, $symbol.($hasCoaching ? '◇' : ''), $tone];
    }
};
?>

@php
    $stats = $this->statistics;
    $period = $this->period;
    $today = EmployeeProfileStatistics::today();
    $isQuality = $this->mode === 'quality';
    $schedule = $stats['schedule'];
    $quality = $stats['quality'];
    $activeDate = in_array($this->selectedDay, $this->dates, true) ? $this->selectedDay
        : ($isQuality ? $stats['activity']->keys()->first() : null);
    $activeDate ??= $period->isSameMonth($today) ? $today->toDateString() : $period->toDateString();
    $day = $stats['days']->get($activeDate);
    $entries = $stats['activity']->get($activeDate, collect());
    $applications = $stats['applications'];
    $applicationGroups = [
        'На рассмотрении' => ['pending', 'new', 'open', ''],
        'Одобрено' => ['approved'],
        'Частично одобрено' => ['partially_approved'],
        'Отклонено' => ['rejected'],
        'Выдано' => ['issued'],
        'Частично выдано' => ['partially_issued'],
        'Отменено' => ['cancelled'],
        'Рассмотрено' => ['reviewed'],
        'Закрыто' => ['closed'],
    ];
    $applicationCounts = collect($applicationGroups)->map(fn (array $statuses) =>
        array_sum(array_intersect_key($applications ?? [], array_flip($statuses))));
    $applicationTotal = array_sum($applications ?? []);
    $otherApplications = $applicationTotal - $applicationCounts->sum();
    $learning = $stats['learning'];
@endphp

<section aria-labelledby="profile-statistics-title" class="mb-5 space-y-2 text-[#192C42]">
    <div class="rounded-[22px] border border-[#DBE5EC] bg-white p-3 sm:p-4">
        <h2 id="profile-statistics-title" tabindex="-1" x-data
            x-on:profile-upcoming-day-selected.window="$nextTick(() => $el.focus())"
            class="mb-2 scroll-mt-4 text-base font-semibold">Моя статистика</h2>
        <div role="group" aria-label="Раздел статистики" class="grid grid-cols-2 gap-1 rounded-xl bg-[#EDF2F6] p-1">
            @foreach(['schedule' => 'График', 'quality' => 'Качество'] as $value => $label)
                @php
                    $pressed = $isQuality === ($value === 'quality');
                @endphp
                <button type="button" wire:click="chooseMode('{{ $value }}')" aria-pressed="{{ $pressed ? 'true' : 'false' }}"
                    @class(['min-h-11 rounded-lg px-2 text-sm font-semibold focus-visible:outline-2 focus-visible:outline-[#24577C]', 'bg-white text-[#24577C] shadow-sm' => $pressed])>{{ $label }}</button>
            @endforeach
        </div>
        <div class="mt-3 flex items-center justify-between gap-2">
            <h3 class="text-[15px] font-semibold">{{ $period->locale('ru')->translatedFormat('F Y') }}</h3>
            <div class="flex shrink-0 gap-1">
                <button type="button" wire:click="changeMonth(-1)" aria-label="Предыдущий месяц" @disabled($period->year === 2000 && $period->month === 1)
                    class="size-11 rounded-xl bg-[#F2F5F8] text-xl text-[#24577C] focus-visible:outline-2 focus-visible:outline-[#24577C] disabled:opacity-40">‹</button>
                <button type="button" wire:click="changeMonth(1)" aria-label="Следующий месяц" @disabled($period->year === 2100 && $period->month === 12)
                    class="size-11 rounded-xl bg-[#F2F5F8] text-xl text-[#24577C] focus-visible:outline-2 focus-visible:outline-[#24577C] disabled:opacity-40">›</button>
            </div>
        </div>

        @if(($isQuality && $quality === null) || (! $isQuality && $schedule === null))
            <p class="mt-4 text-sm text-[#506479]">Данные пока недоступны.</p>
        @else
            <p class="mt-1 flex flex-wrap items-baseline gap-x-3" aria-live="polite">
                <strong class="text-[36px] font-semibold leading-none tabular-nums">{{ $isQuality ? $quality['controls'] : $schedule['planned_workdays'] }}</strong>
                <span class="text-sm text-[#506479]">{{ $isQuality ? 'Контроли за месяц' : 'Рабочие дни по графику' }}</span>
            </p>

            <ul class="mt-3 flex flex-wrap gap-x-3 gap-y-1 text-xs text-[#506479]">
                @if($isQuality)
                    <li><span aria-hidden="true" class="rounded bg-[#E7EDF8] px-1 text-[#394B78]">◇</span> Коучинги · {{ $quality['coachings'] }}</li>
                    @if($quality['average'] !== null)<li>Средний результат · {{ $quality['average'] }}%</li>@endif
                    @foreach($this->qualityTypes() as $zone => [$label, $symbol, $tone])
                        @if($count = $quality['zones']->get($zone, 0))
                            <li><span aria-hidden="true" class="rounded px-1 {{ $tone }}">{{ $symbol }}</span> {{ $label }} · {{ $count }}</li>
                        @endif
                    @endforeach
                    @if($quality['penalties'] || $quality['errors'])
                        <li>Ошибки · {{ $quality['errors'] }}</li><li>Штрафные баллы · {{ $quality['penalties'] }}</li>
                    @endif
                @else
                    @foreach($this->dayTypes() as $key => [, $label, $symbol, $tone])
                        @if($key !== 'planned_workdays' && $schedule[$key] > 0)
                            <li aria-label="{{ $label }}: {{ $schedule[$key] }}"><span aria-hidden="true" class="rounded px-1 {{ $tone }}">{{ $symbol }}</span> {{ $label }} · {{ $schedule[$key] }}</li>
                        @endif
                    @endforeach
                    @if($schedule['pending_request_days'] > 0)<li><span aria-hidden="true" class="rounded bg-[#FFEFD6] px-1 text-[#754607]">◷</span> На согласовании · {{ $schedule['pending_request_days'] }}</li>@endif
                    @if($schedule['manual_change_days'] > 0)<li>Дни с корректировками · {{ $schedule['manual_change_days'] }}</li>@endif
                @endif
            </ul>

            <div class="mt-3 grid grid-cols-7 gap-0.5 sm:gap-1" role="group" aria-label="Дни месяца">
                @foreach(['Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс'] as $weekday)
                    <span class="pb-1 text-center text-[11px] text-[#506479]" aria-hidden="true">{{ $weekday }}</span>
                @endforeach
                @for($blank = 1; $blank < $period->dayOfWeekIso; $blank++)
                    <span aria-hidden="true"></span>
                @endfor
                @foreach($this->dates as $date)
                    @php
                        $calendarDay = $stats['days']->get($date);
                        $dailyEntries = $stats['activity']->get($date, collect());
                        if ($isQuality) {
                            [$label, $symbol, $tone] = $this->qualityDay($dailyEntries);
                        } else {
                            [$label, , $symbol, $tone] = $this->dayType($calendarDay);
                        }
                        $pending = ! $isQuality && ($calendarDay['pending'] ?? false);
                    @endphp
                    <button type="button" wire:key="profile-statistics-{{ $date }}" wire:click="selectDay('{{ $date }}')"
                        aria-label="{{ $this->dateLabel($date) }} · {{ $label }}{{ $pending ? ' · заявка на согласовании' : '' }}"
                        aria-pressed="{{ $activeDate === $date ? 'true' : 'false' }}"
                        @class(['relative min-h-11 min-w-0 rounded-lg px-0 text-xs font-semibold tabular-nums focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-[#24577C]', $tone, 'ring-2 ring-inset ring-[#24577C]' => $activeDate === $date])>
                        {{ $loop->iteration }}
                        @if($symbol !== '')
                            <span aria-hidden="true" class="absolute right-0.5 top-0.5 text-[9px] leading-none">{{ $symbol }}</span>
                        @endif
                        @if($pending)
                            <span aria-hidden="true" class="absolute top-0.5 left-0.5 rounded-sm bg-[#FFEFD6] px-0.5 text-[9px] leading-none text-[#754607]">◷</span>
                        @endif
                    </button>
                @endforeach
            </div>

            <div class="mt-3 border-t border-[#DBE5EC] pt-3" aria-live="polite">
                <p class="text-xs text-[#506479]">{{ $this->dateLabel($activeDate, 'l, j F') }}</p>
                @if($isQuality)
                    @forelse($entries as $entry)
                        @php
                            $params = ['stats_month' => $period->format('Y-m'), 'stats_view' => 'quality', 'stats_day' => $activeDate];
                            $isControl = $entry->item_type === 'control';
                            $url = route($isControl ? 'page-profile.checks.result' : 'page-coachings.show', [$entry, ...$params]);
                        @endphp
                        <a href="{{ $url }}" class="mt-2 block min-h-11 rounded-xl bg-[#F1F5F8] px-3 py-2 text-sm hover:bg-[#E4EFF8]">
                            <span class="font-semibold">{{ $isControl ? 'Контроль' : 'Коучинг' }} @if($entry->apartment) · {{ $entry->apartment->name }} @endif</span>
                            @if($entry->supervisor)<span class="block text-xs text-[#506479]">{{ $entry->supervisor->name }}</span>@endif
                            @if($isControl)
                                <span class="block text-xs text-[#506479]">
                                    {{ $entry->result_zone_label }}
                                    @if($entry->max_points > 0)
                                        · {{ $entry->score_percent ?? (int) round(100 * $entry->total_points / $entry->max_points) }}%
                                        · {{ $entry->total_points }}/{{ $entry->max_points }} баллов
                                    @endif
                                    @if($entry->errors_count > 0) · Ошибки {{ $entry->errors_count }} @endif
                                    @if($entry->penalty_points > 0) · Штраф {{ $entry->penalty_points }} @endif
                                    @if($entry->has_critical_failure) · Критическая ошибка @endif
                                </span>
                            @endif
                        </a>
                    @empty
                        <p class="mt-1 text-sm">{{ $quality['controls'] + $quality['coachings'] === 0 ? 'За этот месяц контролей и коучингов нет.' : 'В этот день записей нет.' }}</p>
                    @endforelse
                @else
                    <p class="mt-1 text-sm font-semibold">{{ $this->dayType($day)[0] }}</p>
                    @if($day['pending'] ?? false)<p class="mt-1 text-sm text-[#754607]">Есть заявка на согласовании</p>@endif
                    <a class="mt-1 inline-flex min-h-11 items-center text-sm text-[#24577C] underline"
                        href="{{ route('page-profile.calendar', ['month' => $period->format('Y-m'), 'day' => $activeDate]) }}">Открыть мой календарь</a>
                @endif
            </div>
        @endif
    </div>

    @if($stats['next_workday'])
        <button type="button" wire:click="showUpcomingWorkday"
            class="flex min-h-11 w-full items-center justify-between gap-2 rounded-xl bg-[#E4EFF8] px-3 py-2 text-left text-sm text-[#24577C]">
            <span>Ближайший рабочий день</span>
            <strong class="shrink-0">{{ $stats['next_workday'] === $today->toDateString() ? 'Сегодня, ' : '' }}{{ $this->dateLabel($stats['next_workday']) }} <span aria-hidden="true">›</span></strong>
        </button>
    @endif

    @if($applications !== null || $learning !== null)
        <div
            class="divide-y divide-[#DBE5EC] rounded-2xl border border-[#DBE5EC] bg-white">
            @if($applications !== null)
                <details wire:key="profile-statistics-applications" wire:ignore.self class="group px-3">
                    <summary class="flex min-h-12 cursor-pointer list-none items-center justify-between gap-2 py-2 text-sm [&::-webkit-details-marker]:hidden">
                        <span>
                            <strong class="block font-semibold">Заявки</strong>
                            <span class="block text-xs text-[#506479]">
                                @if($applicationTotal)
                                    @if($applicationCounts['На рассмотрении']){{ $applicationCounts['На рассмотрении'] }} на рассмотрении · @endif{{ $applicationTotal }} всего
                                @else
                                    Заявок пока нет
                                @endif
                            </span>
                        </span>
                        <span class="text-lg text-[#24577C] group-open:rotate-90" aria-hidden="true">›</span>
                    </summary>
                    <div class="border-t border-[#DBE5EC] pt-2 text-xs text-[#506479]">
                        @if($applicationTotal)
                            <p>За всё время</p>
                            <ul class="mt-1 flex flex-wrap gap-x-3 gap-y-1">
                                @foreach($applicationCounts as $label => $count)
                                    @if($count > 0)<li>{{ $label }} · {{ $count }}</li>@endif
                                @endforeach
                                @if($otherApplications > 0)<li>Другие статусы · {{ $otherApplications }}</li>@endif
                            </ul>
                        @endif
                        <a href="{{ route('page-profile.applications') }}" class="inline-flex min-h-11 items-center text-sm text-[#24577C] underline">Открыть мои заявки</a>
                    </div>
                </details>
            @endif
            @if($learning !== null)
                <details wire:key="profile-statistics-learning" wire:ignore.self class="group px-3">
                    <summary class="flex min-h-12 cursor-pointer list-none items-center justify-between gap-2 py-2 text-sm [&::-webkit-details-marker]:hidden">
                        <span>
                            <strong class="block font-semibold">Обучение</strong>
                            <span class="block text-xs text-[#506479]">{{ $learning['total'] ? $learning['completed'].' из '.$learning['total'].' уроков · '.$learning['percent'].'%' : 'Уроков пока нет' }}</span>
                        </span>
                        <span class="text-lg text-[#24577C] group-open:rotate-90" aria-hidden="true">›</span>
                    </summary>
                    <div class="border-t border-[#DBE5EC] pt-2 text-xs text-[#506479]">
                        @if($learning['resume'])<p>Дальше · {{ $learning['resume']->title }}</p>@endif
                        <a href="{{ $learning['resume'] ? route('learning.lesson', [LearningCatalog::COURSE_SLUG, $learning['resume']->slug]) : route('learning.index') }}"
                            class="inline-flex min-h-11 items-center text-sm text-[#24577C] underline">{{ $learning['resume'] ? 'Открыть урок' : 'Открыть обучение' }}</a>
                    </div>
                </details>
            @endif
        </div>
    @endif
</section>
