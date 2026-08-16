<div class="space-y-2 text-sm">
    @if ($preview['already_imported'])
        <div class="rounded-lg bg-warning-50 p-3 text-warning-700">
            Этот экспорт уже импортировался (пакет №{{ $preview['duplicate_import_id'] }}).
        </div>
    @endif
    @if ($preview['chat_name'] !== '' || $preview['chat_type'] !== '')
        <div>Чат: <strong>{{ $preview['chat_name'] ?: 'Без названия' }}</strong> · тип: <strong>{{ $preview['chat_type'] ?: 'не указан' }}</strong></div>
    @endif
    <div>Сообщений: <strong>{{ $preview['message_count'] }}</strong> · текстовых: <strong>{{ $preview['text_message_count'] }}</strong></div>
    <div>Изображений: <strong>{{ $preview['photo_count'] }}</strong> · документов: <strong>{{ $preview['document_count'] }}</strong> · пропущено: <strong>{{ $preview['skipped_count'] }}</strong></div>
    <div>Медиа-ссылок: <strong>{{ $preview['media_reference_count'] }}</strong> · доступно: <strong>{{ $preview['media_available_count'] }}</strong> · отсутствует: <strong>{{ $preview['media_missing_count'] }}</strong></div>
    @if ($preview['media_not_included_count'] > 0)
        <div class="rounded-lg bg-warning-50 p-3 text-warning-700">В экспорт не включены {{ $preview['media_not_included_count'] }} медиафайлов.</div>
    @endif
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
