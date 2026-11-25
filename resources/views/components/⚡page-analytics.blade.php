<?php

use App\Services\Analytics\AcademyAnalytics;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component
{
    #[Locked]
    public int $period = 7;

    #[Locked]
    public string $tab = 'overview';

    #[Locked]
    public ?string $day = null;

    public function boot(): void
    {
        Gate::authorize('view-academy-analytics');
    }

    public function mount(): void
    {
        $this->period = request()->query('period') === '30' ? 30 : 7;
        $tab = request()->query('tab');
        if (in_array($tab, $this->tabs(), true)) {
            $this->tab = $tab;
        }

        $day = request()->query('day');
        if (is_string($day) && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $day)) {
            $this->day = $day;
        }
    }

    protected function tabs(): array
    {
        if (auth()->user()?->role === 'supervisor') {
            return ['overview', 'staff', 'quality'];
        }

        return Gate::allows('view-employee-calendars')
            ? ['overview', 'staff', 'districts', 'quality']
            : ['overview', 'quality'];
    }

    protected function report(): array
    {
        $viewer = auth()->user();
        $source = app(AcademyAnalytics::class);
        $through = Date::now('Europe/Rome')->toImmutable()->startOfDay();
        $from = $through->subDays($this->period - 1);
        $dates = collect(range(0, $this->period - 1))->map(fn (int $day): string => $from->addDays($day)->toDateString());
        $day = $dates->contains($this->day) ? $this->day : null;
        $quality = null;
        $schedule = null;
        $districts = null;
        $controlsByDay = collect();
        $controls = collect();

        if (in_array($this->tab, ['overview', 'quality'], true)) {
            $controls = $source->quality($viewer, $from, $through);
            $lastPeriod = $source->qualitySummary($source->quality($viewer, $from->subDays($this->period), $from->subDay()));
            $quality = $source->qualitySummary($controls);
            $quality['difference'] = $quality['average'] !== null && $lastPeriod['average'] !== null
                ? $quality['average'] - $lastPeriod['average'] : null;
            $controlsByDay = $controls->groupBy(fn ($control): string => substr($control->report_date, 0, 10));
        }

        if (Gate::allows('view-employee-calendars')) {
            if (in_array($this->tab, ['overview', 'staff'], true)) {
                $schedule = $source->schedule($from, $through);
            }
            if (auth()->user()?->isAdmin() && in_array($this->tab, ['overview', 'districts'], true)) {
                $districts = $source->districtActivity($from, $through);
            }
        }

        return [
            'from' => $from,
            'through' => $through,
            'dates' => $dates,
            'day' => $day,
            'quality' => $quality,
            'scores' => $dates->mapWithKeys(fn (string $date): array => [
                $date => $source->qualitySummary($controlsByDay->get($date, collect())),
            ]),
            'controls' => ($day === null ? $controls : $controlsByDay->get($day, collect()))->reverse()->values(),
            'schedule' => $schedule,
            'districts' => $districts,
        ];
    }
};
?>

<x-slot:title>Аналитика · TRIS</x-slot:title>

<x-slot:header>
    <div class="flex h-[70px] items-center px-5 text-[#213259]">
        <h1 class="text-xl font-semibold">Аналитика</h1>
    </div>
</x-slot:header>

<div class="min-h-full bg-[#F3F6F9] px-4 pb-10 pt-6 text-[#192C42] sm:px-6">
    @php
        $report = $this->report();
        $tabLabels = ['overview' => 'Обзор', 'staff' => 'Сотрудники', 'districts' => 'Районы', 'quality' => 'Качество'];
        $zoneLabels = [
            'green' => ['Зелёная', 'bg-[#187758]'],
            'yellow' => ['Жёлтая', 'bg-[#94610C]'],
            'red' => ['Красная', 'bg-[#AE3B45]'],
            'unknown' => ['Без зоны', 'bg-[#64758A]'],
        ];
    @endphp
    <div class="mx-auto max-w-2xl space-y-4">
        <nav aria-label="Разделы аналитики" class="flex flex-wrap gap-1 rounded-3xl bg-white p-1.5">
            @foreach($this->tabs() as $section)
                <a href="{{ route('page-analytics', ['tab' => $section, 'period' => $period]) }}"
                    @if($tab === $section) aria-current="page" @endif
                    @class(['inline-flex min-h-11 items-center rounded-full px-3 text-sm font-semibold sm:px-4', 'bg-[#213259] text-white' => $tab === $section, 'text-[#42556B]' => $tab !== $section])>
                    {{ $tabLabels[$section] }}
                </a>
            @endforeach
        </nav>
        <div class="flex flex-wrap items-center justify-between gap-3 px-1">
            <span class="text-sm font-medium tabular-nums text-[#42556B]">{{ $report['from']->format('d.m') }} — {{ $report['through']->format('d.m.Y') }}</span>
            <nav aria-label="Период" class="flex gap-1 rounded-full bg-white p-1">
                @foreach([7, 30] as $days)
                    <a href="{{ route('page-analytics', ['tab' => $tab, 'period' => $days]) }}"
                        @if($period === $days) aria-current="true" @endif
                        @class(['inline-flex min-h-10 items-center rounded-full px-3 text-sm font-medium', 'bg-[#DFECF8] text-[#214E76]' => $period === $days, 'text-[#42556B]' => $period !== $days])>{{ $days }} дней</a>
                @endforeach
            </nav>
        </div>

        @if(in_array($tab, ['quality', 'staff'], true))
            <form action="{{ route('page-analytics') }}" method="get" class="flex flex-wrap items-center gap-2 px-1">
                <input type="hidden" name="tab" value="{{ $tab }}">
                <input type="hidden" name="period" value="{{ $period }}">
                <label for="analytics-date" class="text-sm font-medium text-[#42556B]">Дата</label>
                <select id="analytics-date" name="day" class="min-h-11 rounded-xl border border-[#C7D6E5] bg-white pl-3 pr-8 text-sm">
                    <option value="">Весь период</option>
                    @foreach($report['dates']->reverse() as $date)
                        <option value="{{ $date }}" @selected($date === $report['day'])>{{ \Carbon\CarbonImmutable::parse($date)->format('d.m') }}</option>
                    @endforeach
                </select>
                <button type="submit" class="min-h-11 rounded-xl bg-[#DFECF8] px-3 text-sm font-semibold text-[#214E76]">Показать</button>
            </form>
        @endif

        @if($quality = $report['quality'])
            <section aria-labelledby="analytics-quality" class="rounded-[28px] bg-white p-4 sm:p-6">
                <h2 id="analytics-quality" class="text-lg font-semibold">{{ auth()->user()->isAdmin() ? 'Качество контролей' : 'Мои проведённые контроли' }}</h2>
                @if($quality['count'] === 0)
                    <p class="mt-3 text-sm text-[#42556B]">Контролей за этот период нет</p>
                @else
                    <dl class="mt-4 flex flex-wrap gap-x-8 gap-y-3">
                        <div>
                            <dt class="text-sm text-[#42556B]">Средняя оценка</dt>
                            <dd class="text-3xl font-semibold tabular-nums">{{ $quality['average'] === null ? '—' : $quality['average'].'%' }}</dd>
                        </div>
                        <div>
                            <dt class="text-sm text-[#42556B]">Контроли</dt>
                            <dd class="text-3xl font-semibold tabular-nums">{{ $quality['count'] }}</dd>
                        </div>
                        @if($quality['difference'] !== null)
                            <div>
                                <dt class="text-sm text-[#42556B]">К прошлому периоду</dt>
                                <dd class="text-2xl font-semibold tabular-nums">{{ $quality['difference'] < 0 ? '−'.abs($quality['difference']) : ($quality['difference'] > 0 ? '+'.$quality['difference'] : '0') }} п. п.</dd>
                            </div>
                        @endif
                    </dl>
                    @if($quality['scored'] !== $quality['count'])
                        <p class="mt-2 text-sm text-[#42556B]">С оценкой {{ $quality['scored'] }} из {{ $quality['count'] }}</p>
                    @endif

                    <div class="mt-5 flex h-3 gap-0.5 overflow-hidden rounded-full" aria-hidden="true">
                        @foreach($zoneLabels as $zone => [$label, $color])
                            @if($count = $quality['zones'][$zone] ?? 0)
                                <span class="{{ $color }}" style="flex: {{ $count }}"></span>
                            @endif
                        @endforeach
                    </div>
                    <dl class="mt-3 flex flex-wrap gap-x-4 gap-y-2 text-sm">
                        @foreach($zoneLabels as $zone => [$label, $color])
                            @if($zone !== 'unknown' || isset($quality['zones'][$zone]))
                                <div class="flex items-center gap-1.5">
                                    <span class="size-2.5 rounded-full {{ $color }}" aria-hidden="true"></span>
                                    <dt>{{ $label }}</dt><dd class="font-semibold tabular-nums">{{ $quality['zones'][$zone] ?? 0 }}</dd>
                                </div>
                            @endif
                        @endforeach
                    </dl>

                    @if($quality['scored'] > 0)
                        <figure class="mt-6">
                            <figcaption class="mb-2 text-sm font-semibold">Средняя оценка по дням, %</figcaption>
                            <div class="flex gap-2">
                                <div class="flex h-28 w-8 shrink-0 flex-col justify-between pb-1 text-right text-xs tabular-nums text-[#42556B]" aria-hidden="true">
                                    <span>100</span><span>50</span><span>0</span>
                                </div>
                                <div class="relative flex min-w-0 flex-1 items-end gap-px border-b border-[#C7D6E5]">
                                    <div aria-hidden="true" class="pointer-events-none absolute inset-x-0 top-1/2 border-t border-[#DDE7EF]"></div>
                                    @foreach($report['scores'] as $date => $score)
                                        @php
                                            $dayLabel = \Carbon\CarbonImmutable::parse($date)->format('d.m');
                                            $label = $dayLabel.' · '.($score['average'] === null ? 'Без оценки' : $score['average'].'%').', контролей: '.$score['count'];
                                        @endphp
                                        <a href="{{ route('page-analytics', ['tab' => 'quality', 'period' => $period, 'day' => $date]) }}#analytics-results"
                                            title="{{ $label }}" aria-label="{{ $label }}" @if($report['day'] === $date) aria-current="true" @endif
                                            class="relative flex h-28 min-w-0 flex-1 items-end justify-center rounded-t-sm focus-visible:outline-2 focus-visible:outline-[#214E76]">
                                            @if($score['average'] !== null)
                                                <span aria-hidden="true" @class(['block min-h-0.5 w-3/4 max-w-8 rounded-t-sm', 'bg-[#213259]' => $report['day'] === $date, 'bg-[#5289B5]' => $report['day'] !== $date])
                                                    style="height: {{ $score['average'] }}%"></span>
                                            @endif
                                        </a>
                                    @endforeach
                                </div>
                            </div>
                            <div class="ml-10 flex justify-between pt-1 text-xs tabular-nums text-[#42556B]" aria-hidden="true">
                                <span>{{ $report['from']->format('d.m') }}</span>
                                <span>{{ $report['through']->format('d.m') }}</span>
                            </div>
                        </figure>
                    @endif
                    @if($tab === 'overview')
                        <a href="{{ route('page-analytics', ['tab' => 'quality', 'period' => $period]) }}" class="mt-3 inline-flex min-h-11 items-center font-semibold text-[#214E76]">Открыть контроли →</a>
                    @endif
                @endif
            </section>
        @endif

        @if($schedule = $report['schedule'])
            @include('components.analytics.schedule', ['schedule' => $schedule, 'dates' => $report['dates'], 'day' => $report['day'], 'period' => $period, 'showStaff' => $tab === 'staff'])
        @endif

        @if($report['districts'] !== null)
            @include('components.analytics.districts', ['districts' => $report['districts'], 'period' => $period, 'overview' => $tab === 'overview'])
        @endif

        @if($tab === 'quality')
            <section id="analytics-results" aria-labelledby="analytics-results-heading" class="rounded-[28px] bg-white p-4 sm:p-6">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h2 id="analytics-results-heading" class="text-lg font-semibold">Результаты @if($report['day']) за {{ \Carbon\CarbonImmutable::parse($report['day'])->format('d.m') }} @endif</h2>
                    @if($report['day'])
                        <a href="{{ route('page-analytics', ['tab' => 'quality', 'period' => $period]) }}#analytics-results" class="inline-flex min-h-11 items-center text-sm font-semibold text-[#214E76]">Весь период</a>
                    @endif
                </div>
                <ul class="mt-2 divide-y divide-[#E0E8EF]">
                    @forelse($report['controls'] as $control)
                        <li>
                            <a href="{{ route('page-profile.checks.result', $control) }}" class="flex min-h-12 items-center justify-between gap-3 py-2 text-sm">
                                <div class="min-w-0">
                                    <span class="block break-words font-semibold">{{ $control->cleaner?->name ?? 'Сотрудник не указан' }}</span>
                                    <span class="block break-words text-[#42556B]">{{ \Carbon\CarbonImmutable::parse($control->report_date)->format('d.m') }} @if($control->apartment) · {{ $control->apartment->name }} @endif</span>
                                </div>
                                <span class="shrink-0 text-right tabular-nums">{{ $control->max_points > 0 ? ($control->score_percent ?? round(100 * $control->total_points / $control->max_points)).'%' : '—' }}<span class="block text-xs text-[#42556B]">{{ $zoneLabels[$control->result_zone][0] ?? 'Без зоны' }}</span></span>
                            </a>
                        </li>
                    @empty
                        <li class="py-3 text-sm text-[#42556B]">Нет результатов за выбранные даты</li>
                    @endforelse
                </ul>
            </section>
        @endif
    </div>
</div>
