<x-knowledge-layout title="Все статьи">
    <header class="knowledge-header"><p class="knowledge-eyebrow">TRIS Knowledge</p><h1>Все статьи</h1>
        <p class="knowledge-lead">Подробности о системах, функциях и процессах — в одном месте.</p>
    </header>
    <x-knowledge.filters :filters="$filters" :action="route('knowledge.entities')" />
    <div class="knowledge-grid knowledge-catalog">
        @forelse($entities as $entity)<x-knowledge.node :entity="$entity" />
        @empty<div class="knowledge-empty"><h2>Ничего не найдено</h2><p>Попробуйте другой запрос или сбросьте фильтры.</p></div>@endforelse
    </div>
    <div class="knowledge-pagination">{{ $entities->links() }}</div>
</x-knowledge-layout>
