@props(['filters', 'action'])
<form class="knowledge-search" method="get" action="{{ $action }}" role="search">
    <label class="knowledge-search-field">
        <span>Найти в системе</span>
        <input type="search" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Название, описание или текст статьи" maxlength="200">
    </label>
    <label><span>Тип</span><select name="type">
        <option value="">Все типы</option>
        @foreach(\App\Models\KnowledgeEntity::TYPES as $key => $label)<option value="{{ $key }}" @selected(($filters['type'] ?? '') === $key)>{{ $label }}</option>@endforeach
    </select></label>
    <label><span>Статус</span><select name="status">
        <option value="">Все статусы</option>
        @foreach(\App\Models\KnowledgeEntity::STATUSES as $key => $label)<option value="{{ $key }}" @selected(($filters['status'] ?? '') === $key)>{{ $label }}</option>@endforeach
    </select></label>
    <button type="submit">Найти</button>
    @if(array_filter($filters))<a href="{{ $action }}">Сбросить</a>@endif
</form>
