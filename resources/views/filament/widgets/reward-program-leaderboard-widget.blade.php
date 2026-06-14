@php
    $isTrisMare = ! $record || str($record?->name ?? '')
        ->lower()
        ->contains('tris mare');

    $leaders = $isTrisMare
        ? \App\Models\TrisMareSnapshot::query()
            ->orderByRaw('rating is null')
            ->orderBy('rating')
            ->get()
        : ($record?->leaderboard() ?? collect());

    $participantsCount = $leaders->count();
    $totalPoints = $leaders->sum(fn ($item) => (int) $item->total_points);
    $averagePoints = $participantsCount > 0 ? (int) round($totalPoints / $participantsCount) : 0;

    $reached230 = $leaders->filter(fn ($item) => (int) $item->total_points >= 230)->count();
    $reached320 = $leaders->filter(fn ($item) => (int) $item->total_points >= 320)->count();
    $reached400 = $leaders->filter(fn ($item) => (int) $item->total_points >= 400)->count();

    $lastSyncedAt = $isTrisMare
        ? \App\Models\TrisMareSnapshot::query()->max('synced_at')
        : null;
@endphp

<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">
            TRIS Mare 2026
        </x-slot>

        <x-slot name="description">
            Рейтинг участников из Google Sheets
        </x-slot>

        @if($leaders->isEmpty())
            <div class="text-sm text-gray-500">
                Пока нет данных.
            </div>
        @else
            <div class="grid gap-3 md:grid-cols-6">
                <div class="rounded-xl bg-primary-50 p-4 dark:bg-primary-950">
                    <div class="text-xs font-medium text-primary-600 dark:text-primary-400">
                        Участников
                    </div>
                    <div class="mt-1 text-2xl font-bold text-gray-950 dark:text-white">
                        {{ $participantsCount }}
                    </div>
                </div>

      

       






            <div class="mt-4 overflow-hidden rounded-xl border border-gray-200 dark:border-gray-800">
                <table class="w-full text-left text-sm">
                    <thead class="bg-gray-50 text-xs uppercase text-gray-500 dark:bg-gray-900 dark:text-gray-400">
                        <tr>
                            <th class="px-4 py-3">#</th>
                            <th class="px-4 py-3">Сотрудник</th>
                            <th class="px-4 py-3 text-right">Баллы</th>
                            <th class="px-4 py-3 text-right">До 230</th>
                            <th class="px-4 py-3 text-right">Дней</th>
                            <th class="px-4 py-3">Прогресс</th>
                        </tr>
                    </thead>

                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @foreach($leaders as $index => $leader)
                            @php
                                $rating = $leader->rating ?: ($index + 1);
                                $points = (int) $leader->total_points;
                                $left = max(0, 230 - $points);
                                $progress = min(100, max(0, (int) $leader->progress_percent));

                                $rowClass = match (true) {
                                    $rating === 1 => 'bg-yellow-50/70 dark:bg-yellow-950/20',
                                    $rating === 2 => 'bg-gray-50/80 dark:bg-gray-900/60',
                                    $rating === 3 => 'bg-orange-50/60 dark:bg-orange-950/20',
                                    default => 'bg-white dark:bg-gray-950',
                                };
                            @endphp

                            <tr class="{{ $rowClass }}">
                                <td class="px-4 py-3 font-semibold text-gray-500">
                                    {{ $rating }}
                                </td>

                                <td class="px-4 py-3 font-medium text-gray-950 dark:text-white">
                                    {{ $leader->employee_name }}
                                </td>

                                <td class="px-4 py-3 text-right font-bold text-gray-950 dark:text-white">
                                    {{ $points }}
                                </td>

                                <td class="px-4 py-3 text-right text-gray-500">
                                    {{ $left }}
                                </td>

                                <td class="px-4 py-3 text-right text-gray-500">
                                    {{ (int) $leader->working_days }}
                                </td>

                                <td class="px-4 py-3">
                                    <div class="flex items-center gap-2">
                                        <div class="h-2 w-full overflow-hidden rounded-full bg-gray-100 dark:bg-gray-800">
                                            <div
                                                class="h-full rounded-full bg-primary-600"
                                                style="width: {{ $progress }}%;"
                                            ></div>
                                        </div>

                                        <div class="w-10 text-right text-xs text-gray-500">
                                            {{ $progress }}%
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="mt-3 text-right text-xs text-gray-500">
                Обновлено:
                {{ $lastSyncedAt ? \Carbon\Carbon::parse($lastSyncedAt)->format('d.m.Y H:i') : '—' }}
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>