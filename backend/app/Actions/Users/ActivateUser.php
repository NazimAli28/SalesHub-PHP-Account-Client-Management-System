<?php

namespace App\Actions\Users;

use App\Models\User;

class ActivateUser
{
    public function handle(User $actor, User $user): User
    {
        if (! $user->is_active) {
            $user->update(['is_active' => true]);
            UserAudit::record('activated', $actor, $user);
        }

        return $user;
    }
}
