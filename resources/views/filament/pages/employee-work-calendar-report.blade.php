<x-filament-panels::page>
    @php($report = $this->calendarReport)
    <div class="space-y-6">
        <x-filament::section>
            <div class="grid grid-cols-1 gap-4 md:grid-cols-4">
                <label class="text-sm font-medium text-gray-700 dark:text-gray-200">
                    Период
                    <select wire:model.live="periodType" class="mt-1 block w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900">
                        <option value="month">Месяц</option>
                        <option value="year">Год</option>
                    </select>
                </label>
                @if($periodType === 'month')
                    <label class="text-sm font-medium text-gray-700 dark:text-gray-200">
                        Месяц
                        <input type="month" wire:model.live="month" class="mt-1 block w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900">
                    </label>
                @else
                    <label class="text-sm font-medium text-gray-700 dark:text-gray-200">
                        Год
                        <input type="number" min="2000" max="2100" wire:model.live="year" class="mt-1 block w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900">
                    </label>
                @endif
                <label class="text-sm font-medium text-gray-700 dark:text-gray-200">
                    Сотрудник
                    <select wire:model.live="employeeId" class="mt-1 block w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900">
                        <option value="">Все сотрудники</option>
                        @foreach($this->employeeOptions as $id => $name)
                            <option value="{{ $id }}">{{ $name }}</option>
                        @endforeach
                    </select>
                </label>
                <div class="flex items-end">
                    @if(auth()->user()?->canExportEmployeeCalendars())
                        <x-filament::button wire:click="exportCsv" icon="heroicon-m-arrow-down-tray">
                            Экспорт CSV
                        </x-filament::button>
                    @endif
                </div>
            </div>
            <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">Это плановый график и учёт заявок, а не фиксация фактического присутствия.</p>
        </x-filament::section>

        <div class="grid grid-cols-2 gap-3 xl:grid-cols-4">
            @foreach([
                'planned_workdays' => 'Плановые рабочие дни',
                'regular_off_days' => 'Регулярные выходные',
                'unknown_schedule_days' => 'График не зафиксирован',
                'approved_absence_days' => 'Одобренные отсутствия',
                'pending_request_days' => 'Дни ожидающих заявок',
            ] as $key => $label)
                <x-filament::section>
                    <p class="text-sm text-gray-500 dark:text-gray-400">{{ $label }}</p>
                    <p class="mt-1 text-2xl font-semibold text-gray-950 dark:text-white">{{ $report['totals'][$key] }}</p>
                </x-filament::section>
            @endforeach
        </div>

        <x-filament::section>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[1000px] text-left text-sm">
                    <thead class="border-b border-gray-200 text-gray-600 dark:border-gray-700 dark:text-gray-300">
                        <tr>
                            <th class="px-3 py-3">Сотрудник</th>
                            <th class="px-3 py-3">Плановые рабочие</th>
                            <th class="px-3 py-3">Регулярные выходные</th>
                            <th class="px-3 py-3">График не зафиксирован</th>
                            <th class="px-3 py-3">До начала работы</th>
                            <th class="px-3 py-3">Отгулы</th>
                            <th class="px-3 py-3">Отпуск</th>
                            <th class="px-3 py-3">Неоплачиваемый отпуск</th>
                            <th class="px-3 py-3">Прочие отсутствия</th>
                            <th class="px-3 py-3">Ожидают</th>
                            <th class="px-3 py-3">Ручные изменения</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @forelse($report['employees'] as $row)
                            <tr>
                                <td class="px-3 py-3 font-medium">{{ $row['employee_name'] }}</td>
                                <td class="px-3 py-3">{{ $row['planned_workdays'] }}</td>
                                <td class="px-3 py-3">{{ $row['regular_off_days'] }}</td>
                                <td class="px-3 py-3">{{ $row['unknown_schedule_days'] }}</td>
                                <td class="px-3 py-3">{{ $row['not_employed_days'] }}</td>
                                <td class="px-3 py-3">{{ $row['approved_day_off_days'] }}</td>
                                <td class="px-3 py-3">{{ $row['approved_vacation_days'] }}</td>
                                <td class="px-3 py-3">{{ $row['approved_unpaid_leave_days'] }}</td>
                                <td class="px-3 py-3">{{ $row['other_approved_absence_days'] }}</td>
                                <td class="px-3 py-3">{{ $row['pending_request_days'] }}</td>
                                <td class="px-3 py-3">{{ $row['manual_change_days'] }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="11" class="px-3 py-8 text-center text-gray-500">Нет сотрудников для выбранного фильтра.</td></tr>
                        @endforelse
                    </tbody>
                    @if($report['employees'] !== [])
                        <tfoot class="border-t-2 border-gray-200 font-semibold text-gray-950 dark:border-gray-700 dark:text-white">
                            <tr>
                                <td class="px-3 py-3">Итого</td>
                                <td class="px-3 py-3">{{ $report['totals']['planned_workdays'] }}</td>
                                <td class="px-3 py-3">{{ $report['totals']['regular_off_days'] }}</td>
                                <td class="px-3 py-3">{{ $report['totals']['unknown_schedule_days'] }}</td>
                                <td class="px-3 py-3">{{ $report['totals']['not_employed_days'] }}</td>
                                <td class="px-3 py-3">{{ $report['totals']['approved_day_off_days'] }}</td>
                                <td class="px-3 py-3">{{ $report['totals']['approved_vacation_days'] }}</td>
                                <td class="px-3 py-3">{{ $report['totals']['approved_unpaid_leave_days'] }}</td>
                                <td class="px-3 py-3">{{ $report['totals']['other_approved_absence_days'] }}</td>
                                <td class="px-3 py-3">{{ $report['totals']['pending_request_days'] }}</td>
                                <td class="px-3 py-3">{{ $report['totals']['manual_change_days'] }}</td>
                            </tr>
                        </tfoot>
                    @endif
                </table>
            </div>
        </x-filament::section>
    </div>
</x-filament-panels::page>
