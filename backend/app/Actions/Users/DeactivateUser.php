<?php

namespace App\Actions\Users;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class DeactivateUser
{
    public function __construct(private readonly RevokeUserAccess $revokeAccess) {}

    /**
     * Blocks sign-in and ends the user's sessions and tokens immediately.
     */
    public function handle(User $actor, User $user): User
    {
        return DB::transaction(function () use ($actor, $user): User {
            if ($user->is_active) {
                $user->update(['is_active' => false]);
                UserAudit::record('deactivated', $actor, $user);
            }

            $this->revokeAccess->handle($user);

            return $user;
        });
    }
}
