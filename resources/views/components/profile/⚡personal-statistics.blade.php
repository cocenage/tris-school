<?php

use App\Services\Calendar\EmployeeWorkCalendarStatisticsService;
use App\Services\EmployeeProfileStatistics;
use App\Services\Learning\LearningCatalog;
use Carbon\CarbonImmutable;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

new class extends Component
{
    private const DAY_TYPES = [
        'planned_workdays' => ['Рабочий день по графику', 'Рабочие дни по графику', '•', 'bg-[#D9F39C] text-[#223013]'],
        'regular_off_days' => ['Регулярный выходной', 'Выходные', '–', 'bg-[#2A2D32] text-white'],
        'approved_day_off_days' => ['Одобренный отгул', 'Отгулы', '◇', 'bg-[#BCDAF5] text-[#172D46]'],
        'approved_vacation_days' => ['Одобренный отпуск', 'Отпуск', '○', 'bg-[#DECFFF] text-[#2E1E40]'],
        'approved_unpaid_leave_days' => ['Неоплачиваемый отпуск', 'Неоплачиваемый отпуск', '△', 'bg-[#F1C3A8] text-[#3B281A]'],
        'other_approved_absence_days' => ['Одобренное отсутствие', 'Прочие отсутствия', '□', 'bg-[#DCE0E6] text-[#2A2D33]'],
        'unknown_schedule_days' => ['График не зафиксирован', 'График не зафиксирован', '?', 'bg-[#202226] text-[#DBDDE1] border-dashed border-[#6B7280]'],
        'not_employed_days' => ['До начала работы', 'До начала работы', '–', 'bg-[#202226] text-[#DBDDE1]'],
    ];

    #[Url(as: 'stats_month', history: true)]
    public string $month = '';

    #[Url(as: 'stats_view', history: true)]
    public string $mode = 'schedule';

    #[Url(as: 'stats_day', history: true)]
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
    $started = auth()->user()->work_started_at;
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
    $applicationLabels = array_merge(...array_values($applicationGroups));
    $otherApplications = array_diff_key($applications ?? [], array_flip($applicationLabels));
    $learning = $stats['learning'];
    $mare = $stats['mare'];
@endphp

<section aria-labelledby="profile-statistics-title" class="mb-5 space-y-3">
    <div class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
        <h2 id="profile-statistics-title" class="text-[24px] font-semibold tracking-tight text-[#192C42]">Моя статистика</h2>
        @if($started && $started->toDateString() <= $today->toDateString())
            <p class="text-sm text-[#526579]">В TRIS {{ $started->toImmutable()->locale('ru')->diffForHumans($today, ['syntax' => \Carbon\CarbonInterface::DIFF_ABSOLUTE, 'parts' => 2]) }}</p>
        @endif
    </div>

    @if($stats['next_workday'])
        <a class="flex min-h-11 items-center justify-between gap-2 rounded-2xl bg-[#E9F2F9] px-3 py-2 text-sm text-[#214F75]" href="{{ route('page-profile.calendar', ['month' => substr($stats['next_workday'], 0, 7), 'day' => $stats['next_workday']]) }}">
            <span>Ближайший рабочий день</span>
            <strong>{{ $stats['next_workday'] === $today->toDateString() ? 'Сегодня, ' : '' }}{{ $this->dateLabel($stats['next_workday']) }} <span aria-hidden="true">›</span></strong>
        </a>
    @endif

    <div class="overflow-hidden rounded-[26px] bg-[#191B1E] p-3 text-[#F4F4F5] sm:p-4">
        <div role="group" aria-label="Раздел статистики" class="grid grid-cols-2 gap-1 rounded-full border border-white/15 p-1">
            @foreach(['schedule' => 'График', 'quality' => 'Качество'] as $value => $label)
                @php
                    $pressed = $isQuality === ($value === 'quality');
                @endphp
                <button type="button" wire:click="chooseMode('{{ $value }}')" aria-pressed="{{ $pressed ? 'true' : 'false' }}"
                    @class(['min-h-11 rounded-full px-2 text-sm font-semibold focus-visible:outline-2 focus-visible:outline-[#D9F39C]', 'bg-white text-[#191B1E]' => $pressed])>{{ $label }}</button>
            @endforeach
        </div>
        <div class="mt-3 flex items-center justify-between gap-2">
            <h3 class="text-[15px] font-semibold">{{ $period->locale('ru')->translatedFormat('F Y') }}</h3>
            <div class="flex shrink-0 gap-1">
                <button type="button" wire:click="changeMonth(-1)" aria-label="Предыдущий месяц" @disabled($period->year === 2000 && $period->month === 1)
                    class="size-11 rounded-full bg-white/10 text-xl focus-visible:outline-2 focus-visible:outline-[#D9F39C] disabled:opacity-40">‹</button>
                <button type="button" wire:click="changeMonth(1)" aria-label="Следующий месяц" @disabled($period->year === 2100 && $period->month === 12)
                    class="size-11 rounded-full bg-white/10 text-xl focus-visible:outline-2 focus-visible:outline-[#D9F39C] disabled:opacity-40">›</button>
            </div>
        </div>

        @if(($isQuality && $quality === null) || (! $isQuality && $schedule === null))
            <p class="mt-4 text-sm text-[#CDD0D6]">Данные пока недоступны.</p>
        @else
            <p class="mt-1 flex flex-wrap items-baseline gap-x-3" aria-live="polite">
                <strong class="text-[42px] font-semibold leading-none tabular-nums">{{ $isQuality ? $quality['controls'] : $schedule['planned_workdays'] }}</strong>
                <span class="text-sm text-[#CDD0D6]">{{ $isQuality ? 'Контроли за месяц' : 'Рабочие дни по графику' }}</span>
            </p>

            <ul class="mt-3 flex flex-wrap gap-x-3 gap-y-1 text-xs text-[#E2E3E7]">
                @if($isQuality)
                    <li>◇ Коучинги · {{ $quality['coachings'] }}</li>
                    @if($quality['average'] !== null)<li>Средний результат · {{ $quality['average'] }}%</li>@endif
                    @foreach(['green' => 'Зелёная зона', 'yellow' => 'Жёлтая зона', 'red' => 'Красная зона', '' => 'Без зоны'] as $zone => $label)
                        @if($count = $quality['zones']->get($zone, 0))<li>{{ $label }} · {{ $count }}</li>@endif
                    @endforeach
                    @if($quality['penalties'] || $quality['errors'])
                        <li>Ошибки · {{ $quality['errors'] }}</li><li>Штрафные баллы · {{ $quality['penalties'] }}</li>
                    @endif
                @else
                    @foreach($this->dayTypes() as $key => [, $label, $symbol])
                        @if($key !== 'planned_workdays' && $schedule[$key] > 0)
                            <li aria-label="{{ $label }}: {{ $schedule[$key] }}">{{ $symbol }} {{ $label }} · {{ $schedule[$key] }}</li>
                        @endif
                    @endforeach
                    @if($schedule['pending_request_days'] > 0)<li>◷ На согласовании · {{ $schedule['pending_request_days'] }}</li>@endif
                    @if($schedule['manual_change_days'] > 0)<li>Дни с корректировками · {{ $schedule['manual_change_days'] }}</li>@endif
                @endif
            </ul>

            <div class="mt-3 grid grid-cols-7 gap-0.5 sm:gap-1" role="group" aria-label="Дни месяца">
                @foreach(['Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс'] as $weekday)
                    <span class="pb-1 text-center text-[11px] text-[#C9CCD3]" aria-hidden="true">{{ $weekday }}</span>
                @endforeach
                @for($blank = 1; $blank < $period->dayOfWeekIso; $blank++)
                    <span aria-hidden="true"></span>
                @endfor
                @foreach($this->dates as $date)
                    @php
                        $calendarDay = $stats['days']->get($date);
                        $dailyEntries = $stats['activity']->get($date, collect());
                        $hasControl = $dailyEntries->contains('item_type', 'control');
                        $hasCoaching = $dailyEntries->contains('item_type', 'coaching');
                        [$label, , $symbol, $tone] = $isQuality
                            ? [
                                $hasControl && $hasCoaching ? 'Контроль и коучинг' : ($hasControl ? 'Контроль' : ($hasCoaching ? 'Коучинг' : 'Без записей')),
                                '',
                                ($hasControl ? '✓' : '').($hasCoaching ? '◇' : ''),
                                $hasControl ? 'bg-[#D9F39C] text-[#223013]' : ($hasCoaching ? 'bg-[#DECFFF] text-[#2E1E40]' : 'bg-[#2A2D32] text-white'),
                            ]
                            : $this->dayType($calendarDay);
                        $pending = ! $isQuality && ($calendarDay['pending'] ?? false);
                    @endphp
                    <button type="button" wire:key="profile-statistics-{{ $date }}" wire:click="selectDay('{{ $date }}')"
                        aria-label="{{ $this->dateLabel($date) }} · {{ $label }}{{ $pending ? ' · заявка на согласовании' : '' }}"
                        aria-pressed="{{ $activeDate === $date ? 'true' : 'false' }}"
                        @class(['grid min-h-11 min-w-0 content-center justify-items-center rounded-lg border border-transparent px-0 text-xs font-semibold tabular-nums focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-[#D9F39C]', $tone, 'ring-2 ring-inset ring-[#7DA7DC]' => $activeDate === $date])>
                        {{ $loop->iteration }}
                        @if($pending || $symbol !== '')
                            <span aria-hidden="true" class="text-[11px] leading-none">{{ $pending ? '◷' : $symbol }}</span>
                        @endif
                    </button>
                @endforeach
            </div>

            <div class="mt-3 border-t border-white/15 pt-3" aria-live="polite">
                <p class="text-xs text-[#CDD0D6]">{{ $this->dateLabel($activeDate, 'l, j F') }}</p>
                @if($isQuality)
                    @forelse($entries as $entry)
                        @php
                            $params = ['stats_month' => $period->format('Y-m'), 'stats_view' => 'quality', 'stats_day' => $activeDate];
                            $isControl = $entry->item_type === 'control';
                            $url = route($isControl ? 'page-profile.checks.result' : 'page-coachings.show', [$entry, ...$params]);
                        @endphp
                        <a href="{{ $url }}" class="mt-2 block min-h-11 rounded-xl bg-white/10 px-3 py-2 text-sm hover:bg-white/15">
                            <span class="font-semibold">{{ $isControl ? 'Контроль' : 'Коучинг' }} @if($entry->apartment) · {{ $entry->apartment->name }} @endif</span>
                            @if($entry->supervisor)<span class="block text-xs text-[#CDD0D6]">{{ $entry->supervisor->name }}</span>@endif
                            @if($isControl)
                                <span class="block text-xs text-[#CDD0D6]">
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
                    @if($day['pending'] ?? false)<p class="mt-1 text-sm text-[#F6D08C]">Есть заявка на согласовании</p>@endif
                    <a class="mt-1 inline-flex min-h-11 items-center text-sm text-[#D9F39C] underline"
                        href="{{ route('page-profile.calendar', ['month' => $period->format('Y-m'), 'day' => $activeDate]) }}">Открыть мой календарь</a>
                @endif
            </div>
        @endif
    </div>

    @if($applications !== null || $learning !== null || $mare)
        <div class="divide-y divide-[#E0E7EE] rounded-[22px] bg-[#F3F6F9] px-3 text-[#192C42]">
            @if($applications !== null)
                <a href="{{ route('page-profile.applications') }}" class="flex min-h-11 items-center justify-between gap-2 py-3">
                    <div>
                        <h3 class="text-sm font-semibold">Заявки</h3>
                        <p class="text-xs text-[#526579]">
                            @foreach($applicationGroups as $label => $statuses)
                                @php($count = array_sum(array_intersect_key($applications, array_flip($statuses))))
                                @if($count > 0)<span class="mr-2 inline-block">{{ $label }} · {{ $count }}</span>@endif
                            @endforeach
                            @if($otherApplications !== [])<span>Другие статусы · {{ array_sum($otherApplications) }}</span>@endif
                            @if($applications === [])Заявок пока нет@endif
                        </p>
                    </div>
                    <span aria-hidden="true">›</span>
                </a>
            @endif
            @if($learning !== null)
                <a href="{{ $learning['resume'] ? route('learning.lesson', [LearningCatalog::COURSE_SLUG, $learning['resume']->slug]) : route('learning.index') }}"
                    class="flex min-h-11 items-center justify-between gap-2 py-3">
                    <div>
                        <h3 class="text-sm font-semibold">Обучение</h3>
                        <p class="text-xs text-[#526579]">{{ $learning['total'] ? 'Уроки · '.$learning['completed'].' из '.$learning['total'].' · '.$learning['percent'].'%' : 'Уроков пока нет' }}</p>
                        @if($learning['resume'])<p class="mt-0.5 text-xs text-[#526579]">Дальше · {{ $learning['resume']->title }}</p>@endif
                    </div>
                    <span aria-hidden="true">›</span>
                </a>
            @endif
            @if($mare)
                <details class="py-2">
                    <summary class="min-h-11 cursor-pointer py-2 text-sm font-semibold">TRIS Mare 2026 · {{ $mare->total_points }} баллов @if($mare->rating > 0) · {{ $mare->rating }}-е место @endif</summary>
                    <dl class="grid grid-cols-2 gap-2 pb-2 text-xs text-[#526579]">
                        <div><dt>За день</dt><dd>{{ $mare->daily_points }}</dd></div>
                        <div><dt>За неделю</dt><dd>{{ $mare->weekly_points }}</dd></div>
                        <div><dt>Прогресс программы</dt><dd>{{ $mare->progress_percent }}%</dd></div>
                        <div><dt>Дней в программе</dt><dd>{{ $mare->working_days }}</dd></div>
                    </dl>
                    @if(filled($mare->status))<p class="pb-1 text-xs text-[#526579]">{{ $mare->status }}</p>@endif
                    @if(filled($mare->comment))<p class="pb-1 text-xs text-[#526579]">{{ $mare->comment }}</p>@endif
                    @if($mare->synced_at)<p class="pb-2 text-xs text-[#526579]">Обновлено {{ $mare->synced_at->format('d.m.Y') }}</p>@endif
                    <ul class="space-y-1 pb-2 text-xs text-[#526579]">
                        @foreach($mare->daily_history ?? [] as $item)
                            @if(is_array($item) && isset($item['date'], $item['points']))
                                <li>{{ $item['date'] }} · {{ $item['points'] }} баллов @if(filled($item['comment'] ?? null)) · {{ $item['comment'] }} @endif</li>
                            @endif
                        @endforeach
                    </ul>
                </details>
            @endif
        </div>
    @endif
</section>
