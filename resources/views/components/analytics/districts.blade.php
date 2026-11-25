<section aria-labelledby="analytics-districts" class="rounded-[28px] bg-white p-4 sm:p-6">
    <h2 id="analytics-districts" class="text-lg font-semibold">Ситуации ОКС по районам</h2>
    @if($districts->isEmpty())
        <p class="mt-3 text-sm text-[#42556B]">Данные журнала ОКС недоступны</p>
    @else
        @php
    $maxTransitions = max(1, $districts->max('opened'), $districts->max('closed'));
@endphp
        @php
    $available = $districts->whereNotNull('opened');
@endphp
        @if($available->isNotEmpty())
            @if($available->count() !== $districts->count())
                <p class="mt-3 text-sm text-[#42556B]">Итоги по доступным районам</p>
            @endif
            <dl class="mt-4 grid grid-cols-2 gap-3">
                <div>
                    <dt class="text-sm text-[#42556B]">Открытия, включая повторные</dt>
                    <dd class="text-2xl font-semibold tabular-nums">{{ $available->sum('opened') }}</dd>
                </div>
                <div>
                    <dt class="text-sm text-[#42556B]">Закрытия</dt>
                    <dd class="text-2xl font-semibold tabular-nums">{{ $available->sum('closed') }}</dd>
                </div>
            </dl>
        @endif
        <ul class="mt-4 divide-y divide-[#E0E8EF]">
            @foreach($districts as $district)
                <li class="py-3">
                    <h3 class="mb-2 font-semibold">{{ $district['label'] }}</h3>
                    @if($district['opened'] === null)
                        <p class="text-sm text-[#42556B]">Источник района недоступен</p>
                    @elseif($district['opened'] === 0 && $district['closed'] === 0)
                        <p class="text-sm text-[#42556B]">За период нет записей об открытиях или закрытиях</p>
                    @else
                        @foreach(['opened' => ['Открытия', 'bg-[#5289B5]'], 'closed' => ['Закрытия', 'bg-[#187758]']] as $key => [$label, $color])
                            <div class="mb-1.5 grid grid-cols-[5.25rem_minmax(0,1fr)_2rem] items-center gap-2 text-sm">
                                <span>{{ $label }}</span>
                                <span class="h-2.5 overflow-hidden rounded-full bg-[#E8EFF5]" aria-hidden="true"><span class="block h-full {{ $color }}" style="width: {{ $district[$key] / $maxTransitions * 100 }}%"></span></span>
                                <span class="text-right font-semibold tabular-nums">{{ $district[$key] }}</span>
                            </div>
                        @endforeach
                    @endif
                </li>
            @endforeach
        </ul>
        @if($overview)
            <a href="{{ route('page-analytics', ['tab' => 'districts', 'period' => $period]) }}" class="mt-1 inline-flex min-h-11 items-center font-semibold text-[#214E76]">Все районы →</a>
        @endif
    @endif
</section>
