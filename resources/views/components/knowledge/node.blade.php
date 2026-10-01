@props(['entity'])
<a class="knowledge-node" href="{{ route('knowledge.show', $entity) }}">
    <div class="knowledge-node-top">
        <span class="knowledge-icon" aria-hidden="true">{{ $entity->icon ?: '◇' }}</span>
        <span class="knowledge-status" data-status="{{ $entity->status }}">{{ \App\Models\KnowledgeEntity::STATUSES[$entity->status] ?? $entity->status }}</span>
    </div>
    <span class="knowledge-eyebrow">{{ \App\Models\KnowledgeEntity::TYPES[$entity->type] ?? $entity->type }}</span>
    <h3>{{ $entity->title }}</h3>
    @if($entity->summary)<p>{{ $entity->summary }}</p>@endif
    <span class="knowledge-node-link">Открыть статью <span aria-hidden="true">↗</span></span>
</a>
