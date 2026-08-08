<div class="space-y-2 text-sm">
    @if ($preview['already_imported'])
        <div class="rounded-lg bg-warning-50 p-3 text-warning-700">
            Этот экспорт уже импортировался (пакет №{{ $preview['duplicate_import_id'] }}).
        </div>
    @endif
    <div>Сообщений: <strong>{{ $preview['message_count'] }}</strong></div>
    <div>Изображений: <strong>{{ $preview['photo_count'] }}</strong> · документов: <strong>{{ $preview['document_count'] }}</strong> · пропущено: <strong>{{ $preview['skipped_count'] }}</strong></div>
    @if ($preview['date_from'] || $preview['date_to'])
        <div>Диапазон: {{ $preview['date_from'] }} — {{ $preview['date_to'] }}</div>
    @endif
    @if ($preview['samples'] !== [])
        <div class="mt-3 font-medium">Первые сообщения</div>
        <ul class="list-disc space-y-1 pl-5">
            @foreach ($preview['samples'] as $sample)
                <li>{{ $sample }}</li>
            @endforeach
        </ul>
    @endif
</div>
