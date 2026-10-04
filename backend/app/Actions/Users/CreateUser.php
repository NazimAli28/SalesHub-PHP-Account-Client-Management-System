<?php

namespace App\Actions\Users;

use App\Enums\RoleName;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CreateUser
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(User $actor, array $attributes, RoleName $role): User
    {
        return DB::transaction(function () use ($actor, $attributes, $role): User {
            $user = new User($attributes);
            $user->is_active = (bool) ($attributes['is_active'] ?? true);
            $user->save();
            $user->syncRoles([$role->value]);

            UserAudit::record('role_changed', $actor, $user, ['from' => [], 'to' => [$role->value]]);

            return $user;
        });
    }
}
