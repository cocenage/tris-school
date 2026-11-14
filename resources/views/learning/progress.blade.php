<div class="space-y-2">
    <p class="text-sm" role="status">Пройдено {{ $progress['completed'] }} из {{ $progress['total'] }} · {{ $progress['percent'] }}%</p>
    <progress class="h-2 w-full" value="{{ $progress['completed'] }}" max="{{ max(1, $progress['total']) }}" aria-label="Прогресс курса">{{ $progress['percent'] }}%</progress>
    @if ($progress['total'] && $progress['completed'] === $progress['total'])
        <p class="font-semibold text-green-700">Курс завершён</p>
    @endif
</div>
