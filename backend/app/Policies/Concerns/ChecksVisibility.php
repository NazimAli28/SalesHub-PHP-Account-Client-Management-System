<?php

namespace App\Policies\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Keeps single-record checks and list scoping in agreement: both go through the model's `visibleTo` scope.
 */
trait ChecksVisibility
{
    protected function canSee(User $user, Model $model): bool
    {
        if (! $model->exists) {
            return false;
        }

        return $model->newQuery()
            ->scopes(['visibleTo' => [$user]])
            ->whereKey($model->getKey())
            ->exists();
    }

    /**
     * Whether the user holds any of `{resource}.view-all`, `.view-team`, `.view-own`.
     */
    protected function canViewAny(User $user, string $resource): bool
    {
        return $user->can($resource.'.view-all')
            || $user->can($resource.'.view-team')
            || $user->can($resource.'.view-own');
    }

    /**
     * The permission is held and the record is inside the user's scope.
     */
    protected function canOn(User $user, string $permission, Model $model): bool
    {
        return $user->can($permission) && $this->canSee($user, $model);
    }
}
