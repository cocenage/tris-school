<x-filament-panels::page>
    @php
        $totals = $this->overviewTotals;
        $effectiveness = $this->effectiveness;
        $outcomes = $effectiveness['overall'];
    @endphp
    <div class="space-y-6">
        <div class="grid grid-cols-2 gap-3 xl:grid-cols-4">
            @foreach([
                ['label' => 'Обработано квартир', 'value' => $totals['treated_apartments']],
                ['label' => 'Всего проверок', 'value' => $totals['reports']],
                ['label' => 'С загрязнениями', 'value' => $totals['with_contamination']],
                ['label' => 'Проверок за 30 дней', 'value' => $totals['last_30_days']],
            ] as $card)
                <x-filament::section>
                    <p class="text-sm text-gray-500 dark:text-gray-400">{{ $card['label'] }}</p>
                    <p class="mt-2 text-3xl font-semibold tabular-nums">{{ $card['value'] }}</p>
                </x-filament::section>
            @endforeach
        </div>

        <x-filament::section>
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Результаты покрытия</h2>
                </div>
                <label class="flex items-center gap-2 text-sm font-medium text-gray-600 dark:text-gray-300">
                    Период
                    <select wire:model.live="effectivenessPeriod" aria-label="Период наблюдений Антикапли"
                        class="rounded-xl border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 focus:border-primary-500 focus:ring-primary-500 dark:border-gray-700 dark:bg-gray-900 dark:text-white">
                        <option value="30">30 дней</option>
                        <option value="90">90 дней</option>
                        <option value="365">365 дней</option>
                        <option value="0">За всё время</option>
                    </select>
                </label>
            </div>

            <p class="mt-2 text-xs text-gray-500">После обработки · без контрольной группы</p>

            @if($outcomes['reports'] === 0)
                <div class="mt-5 rounded-xl border border-dashed border-gray-300 p-7 text-center dark:border-gray-700">
                    <p class="font-medium">Нет проверок за период</p>
                </div>
            @else
                <div class="mt-5 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    @foreach([
                        ['label' => 'Остались загрязнения', 'rate' => $outcomes['residual_rate'], 'value' => $outcomes['residual_yes'], 'total' => $outcomes['residual_observations'], 'note' => 'От всех ответов «да / нет»'],
                        ['label' => 'Удаляются легко', 'rate' => $outcomes['easy_rate'], 'value' => $outcomes['easy'], 'total' => $outcomes['evaluable_removal'], 'note' => 'Только когда грязь была'],
                        ['label' => 'Удаляются с трудом', 'rate' => $outcomes['difficult_rate'], 'value' => $outcomes['difficult'], 'total' => $outcomes['evaluable_removal'], 'note' => 'Только когда грязь была'],
                        ['label' => 'Квартир с наблюдениями', 'rate' => null, 'value' => $effectiveness['apartment_count'], 'total' => null, 'note' => $outcomes['reports'].' анкет после нанесения'],
                    ] as $metric)
                        <div class="rounded-2xl border border-gray-200 bg-gray-50 p-4 dark:border-gray-700 dark:bg-gray-900">
                            <p class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ $metric['label'] }}</p>
                            <p class="mt-2 text-2xl font-bold tabular-nums text-gray-900 dark:text-white">
                                {{ $metric['rate'] !== null ? $metric['rate'].'%' : ($metric['total'] === null ? $metric['value'] : '—') }}
                            </p>
                            <p class="mt-1 text-xs text-gray-500">
                                @if($metric['total'] !== null)
                                    {{ $metric['value'] }} из {{ $metric['total'] }} ·
                                @endif
                                {{ $metric['note'] }}
                            </p>
                        </div>
                    @endforeach
                </div>

                <p class="mt-2 text-xs text-gray-500">Без грязи: {{ $outcomes['without_visible_dirt'] }} · Противоречия: {{ $outcomes['inconsistent'] }} @if($outcomes['evaluable_removal'] < 20) · Малая выборка @endif</p>

                <div class="mt-6 grid gap-6 xl:grid-cols-2">
                    <div>
                        <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Срок после нанесения</h3>
                        <div class="mt-3 space-y-3">
                            @foreach($effectiveness['ages'] as $cohort)
                                <div class="rounded-xl border border-gray-200 px-3 py-3 dark:border-gray-700">
                                    <div class="flex items-center justify-between gap-3 text-sm">
                                        <span class="font-medium">{{ $cohort['label'] }}</span>
                                        <span class="tabular-nums font-semibold">{{ $cohort['residual_rate'] === null ? '—' : $cohort['residual_rate'].'%' }}</span>
                                    </div>
                                    <div class="mt-2 h-2 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-800">
                                        @if($cohort['residual_rate'] !== null)
                                            <div class="h-full rounded-full bg-primary-500" style="width: {{ $cohort['residual_rate'] }}%"></div>
                                        @endif
                                    </div>
                                    <p class="mt-1 text-xs text-gray-500">
                                        {{ $cohort['reports'] }} анкет · {{ $cohort['residual_observations'] }} ответов
                                        @if($cohort['residual_observations'] > 0 && $cohort['residual_observations'] < 20)
                                            · малая выборка
                                        @endif
                                    </p>
                                </div>
                            @endforeach
                        </div>
                    </div>
                    <div>
                        <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Интервал между уборками</h3>
                        <div class="mt-3 space-y-3">
                            @foreach($effectiveness['gaps'] as $cohort)
                                <div class="flex items-center justify-between gap-3 rounded-xl border border-gray-200 px-3 py-3 dark:border-gray-700">
                                    <div class="min-w-0">
                                        <p class="text-sm font-medium">{{ $cohort['label'] }}</p>
                                        <p class="mt-1 text-xs text-gray-500">{{ $cohort['reports'] }} анкет · {{ $cohort['residual_observations'] }} ответов</p>
                                    </div>
                                    <div class="shrink-0 text-right">
                                        <p class="text-sm font-semibold tabular-nums">{{ $cohort['residual_rate'] === null ? '—' : $cohort['residual_rate'].'%' }} остатков</p>
                                        <p class="text-xs text-gray-500">{{ $cohort['easy_rate'] === null ? '—' : $cohort['easy_rate'].'%' }} легко · n={{ $cohort['evaluable_removal'] }}</p>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>

                <div class="mt-6">
                    <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Результат по поверхностям</h3>
                    <div class="mt-3 overflow-x-auto">
                        <table class="w-full min-w-[620px] text-left text-sm">
                            <thead class="border-b border-gray-200 text-xs text-gray-500 dark:border-gray-700">
                                <tr>
                                    <th class="px-3 py-3 font-medium">Поверхность</th>
                                    <th class="px-3 py-3 font-medium">Остатки</th>
                                    <th class="px-3 py-3 font-medium">Легко удалить</th>
                                    <th class="px-3 py-3 font-medium">Трудно удалить</th>
                                    <th class="px-3 py-3 font-medium">Наблюдений</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                                @foreach($effectiveness['surfaces'] as $surface)
                                    <tr>
                                        <td class="px-3 py-3 font-semibold">{{ $surface['label'] }}</td>
                                        <td class="px-3 py-3 tabular-nums">{{ $surface['residual_rate'] === null ? '—' : $surface['residual_rate'].'%' }}</td>
                                        <td class="px-3 py-3 tabular-nums">{{ $surface['easy_rate'] === null ? '—' : $surface['easy_rate'].'%' }}</td>
                                        <td class="px-3 py-3 tabular-nums">{{ $surface['difficult_rate'] === null ? '—' : $surface['difficult_rate'].'%' }}</td>
                                        <td class="px-3 py-3 text-xs text-gray-500">{{ $surface['residual_observations'] }} остатки · {{ $surface['evaluable_removal'] }} удаление</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif

            @if($effectiveness['unlinked_reports'] > 0)
                <p class="mt-4 text-xs text-gray-500">
                    {{ $effectiveness['unlinked_reports'] }} анкет без даты нанесения исключено.
                </p>
            @endif
        </x-filament::section>

        @if($this->apartment)
            <x-filament::section>
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h2 class="text-xl font-semibold">{{ $this->apartment->name }}</h2>
                        @if($this->apartment->address)<p class="text-sm text-gray-500">{{ $this->apartment->address }}</p>@endif
                    </div>
                    <x-filament::button color="gray" icon="heroicon-m-arrow-left" wire:click="clearApartment">
                        Все квартиры
                    </x-filament::button>
                </div>
            </x-filament::section>
            @php
    $stats = $this->statistics;
@endphp
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <x-filament::section>
                    <p class="text-sm text-gray-500">Дата обработки</p>
                    <p class="mt-1 text-xl font-semibold">{{ $stats['application']?->applied_on?->format('d.m.Y') ?? '—' }}</p>
                </x-filament::section>
                <x-filament::section>
                    <p class="text-sm text-gray-500">Дней после обработки</p>
                    <p class="mt-1 text-2xl font-semibold">{{ $stats['days_since_application'] ?? '—' }}</p>
                </x-filament::section>
                <x-filament::section>
                    <p class="text-sm text-gray-500">Дней уборки после контроля</p>
                    <p class="mt-1 text-2xl font-semibold">{{ $stats['recorded_cleaning_days'] ?? '—' }}</p>
                    <p class="text-xs text-gray-500">Зарегистрированных дат</p>
                </x-filament::section>
                <x-filament::section>
                    <p class="text-sm text-gray-500">Анкет после обработки</p>
                    <p class="mt-1 text-2xl font-semibold">{{ $stats['report_count'] }}</p>
                    <p class="text-xs text-gray-500">Последний контроль: {{ $stats['last_control_date'] ? \Carbon\CarbonImmutable::parse($stats['last_control_date'])->format('d.m.Y') : '—' }}</p>
                </x-filament::section>
            </div>

            <x-filament::section>
                <h2 class="text-lg font-semibold">Результаты по поверхностям</h2>
                <div class="mt-4 grid grid-cols-1 gap-3 lg:grid-cols-2">
                    @foreach($stats['surfaces'] as $surface)
                        <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                            <h3 class="font-semibold">{{ $surface['label'] }}</h3>
                            <p class="mt-2 text-sm">Всего: {{ $surface['total'] }} · Не легко: {{ $surface['difficult'] }} · Трудно: {{ $surface['failed'] }} · Остались: {{ $surface['remaining_count'] }}</p>
                            @if($surface['first_issue_date'])
                                <p class="mt-1 text-xs text-gray-500">Первое затруднение: {{ $surface['first_issue_date'] }}, контроль № {{ $surface['first_issue_report_number'] }}</p>
                            @endif
                            @if(count($surface['recent']) > 0)
                                <div class="mt-3 flex flex-wrap gap-1" aria-label="Последние результаты удаляемости">
                                    @foreach($surface['recent'] as $point)
                                        @php
    $indicator = \App\Services\Antigoccia\AntigocciaAssessment::removalIndicator($point['score']);
@endphp
                                        <span title="{{ $point['date'] }} · {{ \App\Services\Antigoccia\AntigocciaAssessment::removalLabel($point['score']) }}"
                                            class="flex h-8 w-8 items-center justify-center rounded-lg text-xs font-semibold {{ $indicator['color'] }}">
                                            {{ $indicator['label'] }}
                                        </span>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            </x-filament::section>

            <x-filament::section>
                <h2 class="text-lg font-semibold">История проверок</h2>
                <div class="mt-3 space-y-3">
                    @forelse($this->history as $report)
                        <details class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                            <summary class="cursor-pointer font-semibold">{{ $report->cleaning_date->format('d.m.Y') }} · {{ $report->cleaner_name }}</summary>
                            <p class="mt-2 text-sm text-gray-500">Отправлено {{ $report->created_at->format('d.m.Y H:i') }} · С прошлой уборки: {{ $report->days_since_previous_cleaning === null ? '—' : $report->days_since_previous_cleaning.' дн.' }}</p>
                            @foreach(\App\Services\Antigoccia\AntigocciaAssessment::SURFACES as $key => $label)
                                @php
    $answer = $report->answers[$key] ?? [];
@endphp
                                <div class="mt-2 border-t border-gray-100 pt-2 text-sm dark:border-gray-800">
                                    <p class="font-medium">{{ $label }}</p>
                                    <p>Загрязнение: {{ $answer['dirt'] ?? '—' }} · Удаление: {{ \App\Services\Antigoccia\AntigocciaAssessment::removalLabel($answer['removal'] ?? null) }} · Остались: {{ ($answer['remaining'] ?? null) === null ? '—' : ($answer['remaining'] === 'yes' ? 'Да' : 'Нет') }}</p>
                                    @if(!empty($report->photos[$key]))
                                        <a href="{{ route('antigoccia.photo', [$report, $key]) }}" target="_blank" rel="noopener" class="text-primary-600 underline">Фото</a>
                                    @endif
                                </div>
                            @endforeach
                            @if(!empty($report->photos['remaining']))
                                <a href="{{ route('antigoccia.photo', [$report, 'remaining']) }}" target="_blank" rel="noopener" class="text-primary-600 text-sm underline">Фото (старая анкета)</a>
                            @endif
                        </details>
                    @empty
                        <p class="text-sm text-gray-500">Нет проверок</p>
                    @endforelse
                </div>
                @if($this->history instanceof \Illuminate\Pagination\LengthAwarePaginator && $this->history->hasPages())
                    <div class="mt-4">{{ $this->history->links() }}</div>
                @endif
            </x-filament::section>
        @else
            <x-filament::section>
                <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <h2 class="text-lg font-semibold">Квартиры под наблюдением</h2>
                    </div>
                    <label class="block w-full max-w-sm text-sm font-medium">
                        <span class="sr-only">Поиск квартиры по названию или адресу</span>
                        <input type="search" wire:model.live.debounce.300ms="apartmentSearch"
                            placeholder="Поиск по квартире или адресу"
                            class="block w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900">
                    </label>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full min-w-[760px] text-left text-sm">
                        <thead class="border-b border-gray-200 text-gray-500 dark:border-gray-700">
                            <tr>
                                <th class="px-3 py-3 font-medium">Квартира</th>
                                <th class="px-3 py-3 font-medium">Обработка</th>
                                <th class="px-3 py-3 font-medium">Дней прошло</th>
                                <th class="px-3 py-3 font-medium">Проверки</th>
                                <th class="px-3 py-3 font-medium">С загрязнениями</th>
                                <th class="px-3 py-3 font-medium">Последний контроль</th>
                                <th class="px-3 py-3"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                            @forelse($this->overviewRows as $row)
                                <tr wire:key="antigoccia-overview-{{ $row['id'] }}">
                                    <td class="px-3 py-3">
                                        <p class="font-semibold">{{ $row['name'] }}</p>
                                        @if($row['address'])<p class="max-w-[250px] truncate text-xs text-gray-500">{{ $row['address'] }}</p>@endif
                                    </td>
                                    <td class="px-3 py-3">{{ $row['applied_on'] ?? '—' }}</td>
                                    <td class="px-3 py-3 tabular-nums">{{ $row['days_since_application'] ?? '—' }}</td>
                                    <td class="px-3 py-3 tabular-nums">{{ $row['reports'] }}</td>
                                    <td class="px-3 py-3">
                                        @if($row['issues'] > 0)
                                            <span class="rounded-lg bg-red-50 px-2 py-1 font-semibold text-red-700 dark:bg-red-950/40 dark:text-red-300">{{ $row['issues'] }}</span>
                                        @else
                                            <span class="text-gray-400">0</span>
                                        @endif
                                    </td>
                                    <td class="px-3 py-3">{{ $row['last_control_date'] ?? '—' }}</td>
                                    <td class="px-3 py-3 text-right">
                                        <x-filament::button size="sm" color="gray" wire:click="selectApartment({{ $row['id'] }})">
                                            Подробнее
                                        </x-filament::button>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="7" class="px-3 py-10 text-center text-gray-500">Нет данных по выбранному поиску</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @if($this->overviewRows->hasPages())
                    <div class="mt-4">{{ $this->overviewRows->links() }}</div>
                @endif
            </x-filament::section>
        @endif
    </div>
</x-filament-panels::page>
