@php
    $isTrisMare = str($record?->name ?? '')
        ->lower()
        ->contains('tris mare');

    $leaders = $isTrisMare
        ? \App\Models\TrisMareSnapshot::query()
            ->whereNotNull('user_id')
            ->orderByRaw('rating is null')
            ->orderBy('rating')
            ->get()
        : ($record?->leaderboard() ?? collect());

    $targetPoints = 230;

    $participantsCount = $leaders->count();
    $totalPoints = $leaders->sum(fn ($item) => (int) ($isTrisMare ? $item->total_points : $item->total_points));
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
            {{ $isTrisMare ? 'TRIS Mare — рейтинг из Google Sheets' : 'Рейтинг участников' }}
        </x-slot>

        @if($leaders->isEmpty())
            <div class="text-sm text-gray-500">
                Пока нет данных.
            </div>
        @else
            @if($isTrisMare)
                <div class="mb-4 grid gap-3 md:grid-cols-3">
                    <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
                        <div class="text-sm text-gray-500">Участников</div>
                        <div class="mt-1 text-2xl font-bold">{{ $participantsCount }}</div>
                    </div>

                    <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
                        <div class="text-sm text-gray-500">Всего баллов</div>
                        <div class="mt-1 text-2xl font-bold">{{ $totalPoints }}</div>
                    </div>

                    <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
                        <div class="text-sm text-gray-500">Средний балл</div>
                        <div class="mt-1 text-2xl font-bold">{{ $averagePoints }}</div>
                    </div>
                </div>

                <div class="mb-4 grid gap-3 md:grid-cols-3">
                    <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
                        <div class="text-sm text-gray-500">230+</div>
                        <div class="mt-1 text-xl font-bold">{{ $reached230 }}</div>
                    </div>

                    <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
                        <div class="text-sm text-gray-500">320+</div>
                        <div class="mt-1 text-xl font-bold">{{ $reached320 }}</div>
                    </div>

                    <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
                        <div class="text-sm text-gray-500">400+</div>
                        <div class="mt-1 text-xl font-bold">{{ $reached400 }}</div>
                    </div>
                </div>

                <div class="mb-4 rounded-xl bg-gray-50 px-4 py-3 text-sm text-gray-500 dark:bg-gray-900">
                    Обновлено:
                    {{ $lastSyncedAt ? \Carbon\Carbon::parse($lastSyncedAt)->format('d.m.Y H:i') : '—' }}
                </div>
            @endif

            <div class="space-y-3">
                @foreach($leaders as $index => $leader)
                    @php
                        if ($isTrisMare) {
                            $name = $leader->employee_name;
                            $points = (int) $leader->total_points;
                            $rating = $leader->rating ?: ($index + 1);
                            $left = max(0, 230 - $points);
                            $progress = min(100, max(0, (int) $leader->progress_percent));
                            $sub = 'До 230: ' . $left . ' · дней: ' . (int) $leader->working_days;
                        } else {
                            $name = $leader->user?->name ?? '—';
                            $points = (int) $leader->total_points;
                            $rating = $index + 1;
                            $left = max(0, $targetPoints - $points);
                            $progress = $targetPoints > 0
                                ? min(100, (int) round(($points / $targetPoints) * 100))
                                : 0;
                            $sub = 'Осталось до первой цели: ' . $left . ' баллов';
                        }
                    @endphp

                    <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
                        <div class="flex items-center justify-between gap-4">
                            <div>
                                <div class="font-semibold">
                                    {{ $rating }}. {{ $name }}
                                </div>

                                <div class="mt-1 text-sm text-gray-500">
                                    {{ $sub }}
                                </div>
                            </div>

                            <div class="text-right">
                                <div class="text-xl font-bold">
                                    {{ $points }}
                                </div>

                                <div class="text-xs text-gray-500">
                                    баллов
                                </div>
                            </div>
                        </div>

                        <div class="mt-3 h-2 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-800">
                            <div
                                class="h-full rounded-full bg-primary-600"
                                style="width: {{ $progress }}%;"
                            ></div>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>