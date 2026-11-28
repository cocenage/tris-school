<x-filament-widgets::widget>
    <div class="mx-auto max-w-6xl space-y-7">
        <header class="space-y-1 py-2">
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ $today->locale('ru')->translatedFormat('l') }}</p>
            <h2 class="text-4xl font-semibold tracking-tight">{{ $today->locale('ru')->translatedFormat('j F') }}</h2>
            <p class="text-lg text-gray-500 dark:text-gray-400">{{ $supervisor ? 'Рабочий день' : 'Обзор Академии' }}</p>
        </header>

        <section class="space-y-3" aria-labelledby="workspace-attention">
            <h2 id="workspace-attention" class="text-lg font-semibold">Требует внимания</h2>
            <div class="divide-y divide-gray-100 overflow-hidden rounded-2xl bg-white dark:divide-gray-800 dark:bg-gray-900">
                @forelse ($attention as $item)
                    <a href="{{ $item['url'] }}" class="flex min-w-0 items-center gap-3 p-4 hover:bg-gray-50 dark:hover:bg-gray-800">
                        <span @class(['flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-gray-100 dark:bg-gray-800', 'text-danger-600 dark:text-danger-400' => $item['critical']])>
                            <x-filament::icon :icon="$item['icon']" class="h-5 w-5" />
                        </span>
                        <span class="min-w-0 flex-1">
                            <span class="block font-medium">{{ $item['label'] }}</span>
                            <span class="block text-sm text-gray-500 dark:text-gray-400">{{ $item['description'] }}</span>
                        </span>
                        <span @class(['shrink-0 text-xl font-semibold tabular-nums', 'text-danger-600 dark:text-danger-400' => $item['critical']])>{{ $item['value'] }}</span>
                        <x-filament::icon icon="heroicon-o-chevron-right" class="h-4 w-4 shrink-0 text-gray-400" />
                    </a>
                @empty
                    <div class="flex items-center gap-3 p-4">
                        <x-filament::icon icon="heroicon-o-check-circle" class="h-6 w-6 text-success-600" />
                        <div><p class="font-medium">Нет сигналов, требующих внимания</p><p class="text-sm text-gray-500 dark:text-gray-400">По доступным показателям</p></div>
                    </div>
                @endforelse
            </div>
        </section>

        @if ($metrics)
            <section class="space-y-3" aria-labelledby="workspace-today">
                <h2 id="workspace-today" class="text-lg font-semibold">Сегодня</h2>
                <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-4">
                    @foreach ($metrics as $item)
                        @if ($item['url'])
                            <a href="{{ $item['url'] }}" class="min-w-0 space-y-2 rounded-2xl bg-white p-4 hover:ring-1 hover:ring-gray-300 dark:bg-gray-900">
                        @else
                            <div class="min-w-0 space-y-2 rounded-2xl bg-white p-4 dark:bg-gray-900">
                        @endif
                            <x-filament::icon :icon="$item['icon']" class="h-5 w-5 text-gray-500" />
                            <p class="text-3xl font-semibold tabular-nums">{{ $item['value'] }}</p>
                            <p class="text-sm font-medium">{{ $item['label'] }}</p>
                            @if ($item['description'])<p class="text-xs text-gray-500 dark:text-gray-400">{{ $item['description'] }}</p>@endif
                        @if ($item['url'])</a>@else</div>@endif
                    @endforeach
                </div>
            </section>
        @endif

        <div class="grid gap-6 lg:grid-cols-2">
            @if ($links)
                <section class="min-w-0 space-y-3" aria-labelledby="workspace-links">
                    <h2 id="workspace-links" class="text-lg font-semibold">Быстрый доступ</h2>
                    <div class="grid grid-cols-3 gap-2 sm:grid-cols-4">
                        @foreach ($links as $link)
                            <a href="{{ $link['url'] }}" class="flex min-w-0 flex-col items-center gap-2 rounded-xl p-2 text-center hover:bg-white dark:hover:bg-gray-900">
                                <span class="flex h-12 w-12 items-center justify-center rounded-xl bg-white dark:bg-gray-900">
                                    <x-filament::icon :icon="$link['icon']" class="h-6 w-6" />
                                </span>
                                <span class="w-full break-words text-xs font-medium">{{ $link['label'] }}</span>
                            </a>
                        @endforeach
                    </div>
                </section>
            @endif
            @if ($activityUrl)
                <section class="min-w-0 space-y-3" aria-labelledby="workspace-activity">
                    <div class="flex items-center justify-between gap-3">
                        <h2 id="workspace-activity" class="text-lg font-semibold">Последние события</h2>
                        <a href="{{ $activityUrl }}" class="text-sm text-gray-500 underline">Все</a>
                    </div>
                    <div class="divide-y divide-gray-100 overflow-hidden rounded-2xl bg-white dark:divide-gray-800 dark:bg-gray-900">
                        @forelse ($activity as $item)
                            <a href="{{ $item['url'] }}" class="flex min-w-0 items-center gap-3 p-4 hover:bg-gray-50 dark:hover:bg-gray-800">
                                <span class="min-w-0 flex-1"><span class="block break-words font-medium">{{ $item['label'] }}</span><span class="block text-sm text-gray-500 dark:text-gray-400">{{ $item['description'] }}</span></span>
                                <span class="shrink-0 text-xs text-gray-500">{{ $item['time'] }}</span>
                            </a>
                        @empty
                            <p class="p-4 text-sm text-gray-500">Пока нет событий</p>
                        @endforelse
                    </div>
                </section>
            @endif
        </div>
    </div>
</x-filament-widgets::widget>
