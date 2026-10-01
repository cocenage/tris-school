<x-knowledge-layout :title="$entity->title">
    <div class="knowledge-article-layout">
        <aside class="knowledge-sidebar"><nav aria-label="Навигация статьи">
            <a href="{{ route('knowledge.roadmap') }}">← Roadmap</a>
            <a href="{{ route('knowledge.entities') }}">Все статьи</a>
            @can('update', $entity)<a href="{{ \App\Filament\Resources\KnowledgeEntities\KnowledgeEntityResource::getUrl('edit', ['record' => $entity]) }}">Редактировать ↗</a>@endcan
        </nav></aside>
        <article class="knowledge-article">
            <header class="knowledge-article-header">
                <div class="knowledge-article-meta"><span class="knowledge-icon" aria-hidden="true">{{ $entity->icon ?: '◇' }}</span>
                    <span class="knowledge-eyebrow">{{ \App\Models\KnowledgeEntity::TYPES[$entity->type] ?? $entity->type }}</span>
                    <span class="knowledge-status" data-status="{{ $entity->status }}">{{ \App\Models\KnowledgeEntity::STATUSES[$entity->status] ?? $entity->status }}</span>
                </div>
                <h1>{{ $entity->title }}</h1>
                @if($entity->summary)<p class="knowledge-lead">{{ $entity->summary }}</p>@endif
            </header>
            <div class="knowledge-prose">{!! $entity->renderedBody() !!}</div>
            @if($entity->outgoingRelations->isNotEmpty() || $entity->incomingRelations->isNotEmpty())
                <section class="knowledge-relations"><h2>Связи</h2>
                    @foreach(['outgoingRelations' => 'outgoing', 'incomingRelations' => 'incoming'] as $relationship => $direction)
                        @foreach($entity->$relationship as $relation)
                            @php($related = $direction === 'outgoing' ? $relation->target : $relation->source)
                            @if($related)
                                <a class="knowledge-relation" href="{{ route('knowledge.show', $related) }}">
                                    <span class="knowledge-eyebrow">{{ $direction === 'incoming' ? '←' : '→' }} {{ \App\Models\KnowledgeEntityRelation::TYPES[$relation->relation_type] ?? $relation->relation_type }}</span>
                                    <strong>{{ $related->title }}</strong>
                                    @if($relation->note)<span>{{ $relation->note }}</span>@endif
                                </a>
                            @endif
                        @endforeach
                    @endforeach
                </section>
            @endif
            @if($linked)
                <section class="knowledge-linked"><h2>Связанный объект TRIS</h2><p class="knowledge-eyebrow">{{ $linked['type'] }}</p>
                    @if($linked['url'])<a class="knowledge-text-link" href="{{ $linked['url'] }}">{{ $linked['label'] }} ↗</a>
                    @else<p>{{ $linked['label'] }}</p>@endif
                </section>
            @endif
        </article>
    </div>
</x-knowledge-layout>
