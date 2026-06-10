@php
    $leaders = $record?->leaderboard() ?? collect();
    $targets = collect($record?->targets ?? [])
        ->map(fn ($points, $name) => [
            'name' => (string) $name,
            'points' => (int) $points,
        ])
        ->sortBy('points')
        ->values();

    $firstTarget = $targets->first();
@endphp

<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">
            Рейтинг участников
        </x-slot>

        @if($leaders->isEmpty())
            <div class="text-sm text-gray-500">
                Пока нет начислений.
            </div>
        @else
            <div class="space-y-3">
                @foreach($leaders as $index => $leader)
                    @php
                        $points = (int) $leader->total_points;
                        $targetPoints = $firstTarget['points'] ?? 230;
                        $left = max(0, $targetPoints - $points);
                        $progress = $targetPoints > 0
                            ? min(100, (int) round(($points / $targetPoints) * 100))
                            : 0;
                    @endphp

                    <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
                        <div class="flex items-center justify-between gap-4">
                            <div>
                                <div class="font-semibold">
                                    {{ $index + 1 }}. {{ $leader->user?->name ?? '—' }}
                                </div>

                                <div class="mt-1 text-sm text-gray-500">
                                    Осталось до первой цели: {{ $left }} баллов
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