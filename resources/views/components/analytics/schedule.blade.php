@php
    $scheduleSeries = [
        'planned_workdays' => ['Рабочие дни по графику', 'bg-[#5289B5]'],
        'regular_off_days' => ['Выходные по графику', 'bg-[#187758]'],
        'approved_absence_days' => ['Согласованные отсутствия', 'bg-[#94610C]'],
        'unknown_schedule_days' => ['График неизвестен', 'bg-[#64758A]'],
    ];
    $daily = $schedule['daily'];
    $selected = $day === null ? null : ($daily->get($day) ?? []);
    $maxDaily = max(1, $daily->max(fn (array $entry) => array_sum(array_intersect_key($entry, $scheduleSeries))));
@endphp
<section aria-labelledby="analytics-schedule" class="rounded-[28px] bg-white p-4 sm:p-6">
    <h2 id="analytics-schedule" class="text-lg font-semibold">График и отдых</h2>
    @if($schedule['staff']->isEmpty())
        <p class="mt-3 text-sm text-[#42556B]">Нет сотрудников с активным профилем</p>
    @else
        <dl class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
            @foreach($scheduleSeries as $key => [$label, $color])
                <div>
                    <dt class="flex items-start gap-1.5 text-sm text-[#42556B]"><span class="mt-1.5 size-2 shrink-0 rounded-full {{ $color }}" aria-hidden="true"></span>{{ $label }}</dt>
                    <dd class="text-2xl font-semibold tabular-nums">{{ $daily->sum($key) }}</dd>
                </div>
            @endforeach
        </dl>

        <figure class="mt-5">
            <figcaption class="mb-2 text-sm font-semibold">Сотрудники по дням</figcaption>
            <div class="flex gap-2">
                <div class="flex h-24 w-8 shrink-0 flex-col justify-between pb-1 text-right text-xs tabular-nums text-[#42556B]" aria-hidden="true"><span>{{ $maxDaily }}</span><span>0</span></div>
                <ul class="flex min-w-0 flex-1 items-end gap-px border-b border-[#C7D6E5]">
                    @foreach($dates as $date)
                        @php
                            $values = $daily->get($date, []);
                            $label = \Carbon\CarbonImmutable::parse($date)->format('d.m').' · '.collect($scheduleSeries)
                                ->map(fn (array $series, string $key) => $series[0].': '.($values[$key] ?? 0))->implode(', ');
                        @endphp
                        <li class="min-w-0 flex-1">
                            <a href="{{ route('page-analytics', ['tab' => 'staff', 'period' => $period, 'day' => $date]) }}#analytics-schedule"
                                title="{{ $label }}" aria-label="{{ $label }}" @if($day === $date) aria-current="true" @endif
                                @class(['flex h-24 items-end justify-center rounded-t-sm focus-visible:outline-2 focus-visible:outline-[#214E76]', 'bg-[#E6F0FA]' => $day === $date])>
                                <span aria-hidden="true" class="flex h-full w-3/4 max-w-8 flex-col-reverse overflow-hidden rounded-t-sm">
                                    @foreach($scheduleSeries as $key => [$name, $color])
                                        @if($count = $values[$key] ?? 0)
                                            <span class="{{ $color }}" style="height: {{ $count / $maxDaily * 100 }}%"></span>
                                        @endif
                                    @endforeach
                                </span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            </div>
            <div class="ml-10 flex justify-between pt-1 text-xs tabular-nums text-[#42556B]" aria-hidden="true">
                <span>{{ \Carbon\CarbonImmutable::parse($dates->first())->format('d.m') }}</span>
                <span>{{ \Carbon\CarbonImmutable::parse($dates->last())->format('d.m') }}</span>
            </div>
        </figure>

        @if($selected !== null)
            <p class="mt-3 rounded-2xl bg-[#F3F6F9] px-3 py-2 text-sm tabular-nums text-[#42556B]">
                <strong>{{ \Carbon\CarbonImmutable::parse($day)->format('d.m') }}</strong> · По графику {{ $selected['planned_workdays'] ?? 0 }} · Выходные {{ $selected['regular_off_days'] ?? 0 }} · Отсутствия {{ $selected['approved_absence_days'] ?? 0 }} · График неизвестен {{ $selected['unknown_schedule_days'] ?? 0 }}
            </p>
        @endif

        @if($showStaff)
            <ul class="mt-4 divide-y divide-[#E0E8EF]">
                @foreach($schedule['staff'] as $row)
                    <li class="py-3 text-sm">
                        <a href="{{ route('employees.calendar', ['employee' => $row['employee'], 'month' => substr($day ?? $dates->last(), 0, 7), 'day' => $day]) }}" class="inline-flex min-h-8 items-center font-semibold text-[#214E76]">{{ $row['employee']->name }} <span class="ml-1" aria-hidden="true">→</span></a>
                        <p class="text-[#42556B]">По графику {{ $row['planned_workdays'] }} · Выходные {{ $row['regular_off_days'] }} · Отсутствия {{ $row['approved_absence_days'] }} @if($row['unknown_schedule_days']) · График неизвестен {{ $row['unknown_schedule_days'] }} @endif</p>
                    </li>
                @endforeach
            </ul>
        @else
            <a href="{{ route('page-analytics', ['tab' => 'staff', 'period' => $period]) }}" class="mt-3 inline-flex min-h-11 items-center font-semibold text-[#214E76]">График по сотрудникам →</a>
        @endif
    @endif
</section>
