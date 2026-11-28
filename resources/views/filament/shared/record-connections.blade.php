<div class="divide-y divide-gray-100 dark:divide-gray-800">
    @forelse ($links as $link)
        <a href="{{ $link['url'] }}" class="flex min-h-11 min-w-0 items-center gap-3 rounded-lg px-2 py-2 hover:bg-gray-50 dark:hover:bg-gray-800">
            <x-filament::icon :icon="$link['icon']" class="h-5 w-5 shrink-0 text-gray-500" />
            <span class="min-w-0 flex-1 break-words font-medium">{{ $link['label'] }}</span>
            @if($link['value'] !== null)<span class="max-w-[45%] break-words text-right text-sm text-gray-500">{{ $link['value'] }}</span>@endif
            <x-filament::icon icon="heroicon-o-chevron-right" class="h-4 w-4 shrink-0 text-gray-400" />
        </a>
    @empty
        <p class="py-2 text-sm text-gray-500">Нет доступных связанных разделов</p>
    @endforelse
</div>
