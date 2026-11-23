<x-filament-panels::page>
    @php($totals = $this->overviewTotals)
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
            @php($stats = $this->statistics)
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
                    <p class="text-sm text-gray-500">Уборок после последнего контроля</p>
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
                            <p class="mt-2 text-sm">Всего: {{ $surface['total'] }} · Трудно: {{ $surface['difficult'] }} · Не удалились: {{ $surface['failed'] }} · Остались: {{ $surface['remaining_count'] }}</p>
                            @if($surface['first_issue_date'])
                                <p class="mt-1 text-xs text-gray-500">Первое затруднение: {{ $surface['first_issue_date'] }}, контроль № {{ $surface['first_issue_report_number'] }}</p>
                            @endif
                            @if(count($surface['recent']) > 0)
                                <div class="mt-3 flex flex-wrap gap-1" aria-label="Последние результаты удаляемости">
                                    @foreach($surface['recent'] as $point)
                                        <span title="{{ $point['date'] }}"
                                            class="flex h-8 w-8 items-center justify-center rounded-lg text-xs font-semibold {{ $point['score'] === null ? 'bg-gray-100 text-gray-500 dark:bg-gray-800' : ($point['score'] === 0 ? 'bg-green-100 text-green-800' : ($point['score'] === 1 ? 'bg-amber-100 text-amber-800' : 'bg-red-100 text-red-800')) }}">
                                            {{ $point['score'] ?? '—' }}
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
                                @php($answer = $report->answers[$key] ?? [])
                                <div class="mt-2 border-t border-gray-100 pt-2 text-sm dark:border-gray-800">
                                    <p class="font-medium">{{ $label }}</p>
                                    <p>Загрязнение: {{ $answer['dirt'] ?? '—' }} · Удаление: {{ ($answer['removal'] ?? null) === null ? '—' : $answer['removal'] }} · Остались: {{ ($answer['remaining'] ?? null) === null ? '—' : ($answer['remaining'] === 'yes' ? 'Да' : 'Нет') }}</p>
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
                        <p class="text-xs text-gray-500">Только квартиры с обработкой или проверками ANTIGOCCIA</p>
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
