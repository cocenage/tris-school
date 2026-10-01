<?php

namespace App\Policies;

use App\Models\KnowledgeEntityRelation;
use App\Models\User;

class KnowledgeEntityRelationPolicy
{
    public function viewAny(User $user): bool
    {
        return app(KnowledgeEntityPolicy::class)->viewAny($user);
    }

    public function view(User $user, KnowledgeEntityRelation $relation): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, KnowledgeEntityRelation $relation): bool
    {
        return $this->viewAny($user);
    }

    public function delete(User $user, KnowledgeEntityRelation $relation): bool
    {
        return $this->viewAny($user);
    }
}
