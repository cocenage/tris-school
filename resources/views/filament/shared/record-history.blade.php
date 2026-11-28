<div class="space-y-3">
    @forelse ($events as $event)
        <div class="flex gap-3">
            <x-filament::icon icon="heroicon-o-clock" class="mt-1 h-5 w-5 shrink-0 text-gray-400" />
            <div class="min-w-0 flex-1">
                <p class="font-medium">{{ $event['label'] }}</p>
                <p class="text-sm text-gray-500">{{ $event['time'] }} · {{ $event['actor'] }}</p>
            </div>
        </div>
    @empty
        <p class="text-sm text-gray-500">{{ $available ? 'В журнале нет записей об изменениях этой сущности' : 'Журнал недоступен' }}</p>
    @endforelse
    @if($available)<p class="text-xs text-gray-500">Только события, записанные в журнале активности.</p>@endif
</div>
