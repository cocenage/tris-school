<?php

namespace App\Http\Controllers;

use App\Models\Apartment;
use App\Models\Instruction;
use App\Models\KnowledgeEntity;
use App\Models\TelegramChat;
use App\Models\TelegramScheduledMessage;
use App\Models\TelegramTopic;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class KnowledgeController extends Controller
{
    public function roadmap(Request $request): View
    {
        [$query, $filters] = $this->search($request);
        $groups = $query->orderBy('group_key')->orderBy('sort_order')->orderBy('title')->get()->groupBy('group_key')
            ->sortBy(fn ($entities, $key): int => array_search($key, array_keys(KnowledgeEntity::GROUPS), true) === false
                ? count(KnowledgeEntity::GROUPS)
                : array_search($key, array_keys(KnowledgeEntity::GROUPS), true));

        return view('knowledge.roadmap', compact('groups', 'filters'));
    }

    public function catalog(Request $request): View
    {
        [$query, $filters] = $this->search($request);
        $entities = $query->orderBy('sort_order')->orderBy('title')->orderBy('id')->paginate(18)->withQueryString();

        return view('knowledge.catalog', compact('entities', 'filters'));
    }

    public function show(KnowledgeEntity $entity): View
    {
        $entity->load(['outgoingRelations.target', 'incomingRelations.source']);
        $linked = null;
        if (isset(KnowledgeEntity::LINKED_TYPES[$entity->linked_type ?? ''])) {
            $object = $entity->linked;
            $linked = match (true) {
                $object instanceof Instruction && $object->status === 'published' && $object->is_public => [
                    'label' => $object->title, 'type' => 'Инструкция', 'url' => route('page-home.instructions.single', $object->slug),
                ],
                $object instanceof Apartment && Gate::allows('view', $object) => [
                    'label' => $object->name, 'type' => 'Квартира', 'url' => route('page-apartments.show', $object),
                ],
                $object instanceof TelegramChat => ['label' => $object->title ?: 'Telegram-чат', 'type' => 'Telegram-чат', 'url' => null],
                $object instanceof TelegramTopic => ['label' => $object->title ?: 'Telegram-тема', 'type' => 'Telegram-тема', 'url' => null],
                $object instanceof TelegramScheduledMessage => ['label' => $object->name, 'type' => 'Плановое сообщение', 'url' => null],
                default => null,
            };
        }

        return view('knowledge.show', compact('entity', 'linked'));
    }

    private function search(Request $request): array
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:200'],
            'type' => ['nullable', Rule::in(array_keys(KnowledgeEntity::TYPES))],
            'status' => ['nullable', Rule::in(array_keys(KnowledgeEntity::STATUSES))],
        ]);
        $query = KnowledgeEntity::query()->select(['id', 'title', 'slug', 'type', 'status', 'summary', 'icon', 'group_key', 'sort_order']);
        $search = trim((string) ($filters['q'] ?? ''));
        if ($search !== '') {
            $query->where(fn (Builder $query) => $query->where('title', 'like', '%'.$search.'%')
                ->orWhere('summary', 'like', '%'.$search.'%')->orWhere('body', 'like', '%'.$search.'%'));
        }
        foreach (['type', 'status'] as $field) {
            if (filled($filters[$field] ?? null)) {
                $query->where($field, $filters[$field]);
            }
        }

        return [$query, $filters];
    }
}
