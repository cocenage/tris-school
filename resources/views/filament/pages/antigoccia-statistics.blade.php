<x-filament-panels::page>
    <div class="space-y-6">
        <x-filament::section>
            <label for="antigoccia-admin-search" class="block text-sm font-semibold">Квартира</label>
            @if($this->apartment)
                <div class="mt-3 flex items-center justify-between gap-3">
                    <div>
                        <p class="font-semibold">{{ $this->apartment->name }}</p>
                        @if($this->apartment->address)<p class="text-sm text-gray-500">{{ $this->apartment->address }}</p>@endif
                    </div>
                    <x-filament::button color="gray" wire:click="clearApartment">Изменить</x-filament::button>
                </div>
            @else
                <input id="antigoccia-admin-search" type="search" wire:model.live.debounce.250ms="apartmentSearch"
                    placeholder="Название или адрес"
                    class="mt-3 block w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900">
                <div class="mt-2 max-h-64 overflow-y-auto rounded-lg border border-gray-200 p-1 dark:border-gray-700">
                    @forelse($this->apartmentMatches as $option)
                        <button type="button" wire:key="antigoccia-admin-{{ $option->id }}"
                            wire:click="selectApartment({{ $option->id }})"
                            class="block w-full rounded-md px-3 py-2 text-left text-sm hover:bg-gray-100 focus-visible:outline focus-visible:outline-2 dark:hover:bg-gray-800">
                            <span class="font-medium">{{ $option->name }}</span>
                            @if($option->address)<span class="ml-2 text-gray-500">{{ $option->address }}</span>@endif
                        </button>
                    @empty
                        <p class="px-3 py-3 text-sm text-gray-500">Квартира не найдена</p>
                    @endforelse
                </div>
            @endif
        </x-filament::section>

        @if($this->apartment)
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
        @endif
    </div>
</x-filament-panels::page>
