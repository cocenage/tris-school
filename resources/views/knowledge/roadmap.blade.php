<x-knowledge-layout title="Roadmap">
    <header class="knowledge-header">
        <p class="knowledge-eyebrow">Библиотека системы</p>
        <h1>Roadmap</h1>
        <p class="knowledge-lead">Системы, боты и процессы TRIS. Что работает сейчас, что развивается и что запланировано.</p>
        @can('viewAny', \App\Models\KnowledgeEntity::class)
            <a class="knowledge-text-link" href="{{ \App\Filament\Resources\KnowledgeEntities\KnowledgeEntityResource::getUrl('index') }}">Управлять статьями ↗</a>
        @endcan
    </header>
    <x-knowledge.filters :filters="$filters" :action="route('knowledge.roadmap')" />
    <div class="knowledge-roadmap">
        @forelse($groups as $key => $entities)
            <section class="knowledge-group" aria-labelledby="group-{{ $loop->index }}">
                <h2 id="group-{{ $loop->index }}">{{ \App\Models\KnowledgeEntity::GROUPS[$key] ?? $key }}</h2>
                <div class="knowledge-grid">
                    @foreach($entities as $entity)<x-knowledge.node :entity="$entity" />@endforeach
                </div>
            </section>
        @empty
            <div class="knowledge-empty"><h2>{{ array_filter($filters) ? 'Ничего не найдено' : 'Карта пока пустая' }}</h2>
                <p>{{ array_filter($filters) ? 'Попробуйте другой запрос или сбросьте фильтры.' : 'Статьи и связи появятся здесь после создания в админ-панели.' }}</p>
            </div>
        @endforelse
    </div>
</x-knowledge-layout>
