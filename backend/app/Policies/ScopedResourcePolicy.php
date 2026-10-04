<?php

namespace App\Policies;

use App\Models\User;
use App\Policies\Concerns\ChecksVisibility;
use Illuminate\Database\Eloquent\Model;

/**
 * CRUD rules shared by the scoped business resources (permission `{resource}.{action}` + record visibility).
 */
abstract class ScopedResourcePolicy
{
    use ChecksVisibility;

    /**
     * Permission prefix, e.g. "leads".
     */
    abstract protected function resource(): string;

    public function viewAny(User $user): bool
    {
        return $this->canViewAny($user, $this->resource());
    }

    public function view(User $user, Model $model): bool
    {
        return $this->canViewAny($user, $this->resource()) && $this->canSee($user, $model);
    }

    public function create(User $user): bool
    {
        return $user->can($this->resource().'.create');
    }

    public function update(User $user, Model $model): bool
    {
        return $this->canOn($user, $this->resource().'.update', $model);
    }

    public function delete(User $user, Model $model): bool
    {
        return $this->canOn($user, $this->resource().'.delete', $model);
    }

    public function requestChange(User $user, Model $model): bool
    {
        return $this->canOn($user, $this->resource().'.request-change', $model);
    }
}
