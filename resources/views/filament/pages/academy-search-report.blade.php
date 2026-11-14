<x-filament-panels::page>
    <p>Считаются отправленные запросы. Повторные поиски увеличивают счётчик. Показаны первые 50 запросов в каждом списке.</p>
    <div class="grid gap-6 lg:grid-cols-2">
        <x-filament::section heading="Популярные запросы">
            <table class="w-full text-left">
                <thead><tr><th class="p-2">Запрос</th><th class="p-2">Поисков</th></tr></thead>
                <tbody>
                    @forelse ($this->popularQueries as $query)
                        <tr><td class="break-all p-2">{{ $query->query }}</td><td class="p-2">{{ $query->searches_count }}</td></tr>
                    @empty
                        <tr><td colspan="2" class="p-2">Поисков пока нет.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </x-filament::section>
        <x-filament::section heading="Запросы без результатов">
            <table class="w-full text-left">
                <thead><tr><th class="p-2">Запрос</th><th class="p-2">Без результатов</th></tr></thead>
                <tbody>
                    @forelse ($this->emptyQueries as $query)
                        <tr><td class="break-all p-2">{{ $query->query }}</td><td class="p-2">{{ $query->no_results_count }}</td></tr>
                    @empty
                        <tr><td colspan="2" class="p-2">Запросов без результатов пока нет.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </x-filament::section>
    </div>
</x-filament-panels::page>
