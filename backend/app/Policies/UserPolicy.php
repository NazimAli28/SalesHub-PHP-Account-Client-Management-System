<?php

namespace App\Policies;

use App\Enums\RoleName;
use App\Models\User;
use App\Policies\Concerns\ChecksVisibility;

class UserPolicy
{
    use ChecksVisibility;

    public function viewAny(User $user): bool
    {
        return $user->can('users.view');
    }

    public function view(User $user, User $target): bool
    {
        return $user->is($target) || ($user->can('users.view') && $this->canSee($user, $target));
    }

    public function create(User $user): bool
    {
        return $user->can('users.create');
    }

    public function update(User $user, User $target): bool
    {
        return $this->canManage($user, 'users.update', $target);
    }

    public function deactivate(User $user, User $target): bool
    {
        return ! $user->is($target)
            && $this->canManage($user, 'users.deactivate', $target)
            && ! $this->isLastActiveAdmin($target);
    }

    public function delete(User $user, User $target): bool
    {
        return ! $user->is($target)
            && $this->canManage($user, 'users.delete', $target)
            && ! $this->isLastActiveAdmin($target);
    }

    /**
     * Whether $user may give $target the role $role (also used when creating a user: pass an unsaved model).
     */
    public function assignRole(User $user, User $target, RoleName $role): bool
    {
        if (! $user->can('users.update') && ! $user->can('users.create')) {
            return false;
        }

        if (! $this->canTouch($user, $target) || ! $this->canGrant($user, $role)) {
            return false;
        }

        // Moving the last active admin out of the admin role would lock everyone out of administration.
        return $role === RoleName::Admin || ! $this->isLastActiveAdmin($target);
    }

    private function canManage(User $user, string $permission, User $target): bool
    {
        return $user->can($permission)
            && $this->canSee($user, $target)
            && $this->canTouch($user, $target);
    }

    /**
     * Admin and support accounts may only be touched with `users.manage-privileged`.
     */
    private function canTouch(User $user, User $target): bool
    {
        return ! $target->hasAnyRole([RoleName::Admin->value, RoleName::Support->value])
            || $user->can('users.manage-privileged');
    }

    private function canGrant(User $user, RoleName $role): bool
    {
        return ! in_array($role, [RoleName::Admin, RoleName::Support], true)
            || $user->can('users.manage-privileged');
    }

    private function isLastActiveAdmin(User $target): bool
    {
        if (! $target->exists || ! $target->is_active || ! $target->hasRole(RoleName::Admin->value)) {
            return false;
        }

        return ! User::query()
            ->role(RoleName::Admin->value)
            ->where('is_active', true)
            ->whereKeyNot($target->getKey())
            ->exists();
    }
}
