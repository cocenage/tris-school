<?php

namespace App\Policies;

use App\Models\KnowledgeEntity;
use App\Models\User;

class KnowledgeEntityPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role === 'admin' && $user->status === 'approved' && $user->is_active !== false;
    }

    public function view(User $user, KnowledgeEntity $entity): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, KnowledgeEntity $entity): bool
    {
        return $this->viewAny($user);
    }

    public function delete(User $user, KnowledgeEntity $entity): bool
    {
        return $this->viewAny($user);
    }
}
