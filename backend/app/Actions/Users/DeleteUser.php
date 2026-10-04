<?php

namespace App\Actions\Users;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class DeleteUser
{
    public function __construct(private readonly RevokeUserAccess $revokeAccess) {}

    /**
     * Soft delete: frees a led team and ends all access. Records the user owns are kept.
     */
    public function handle(User $actor, User $user): void
    {
        DB::transaction(function () use ($actor, $user): void {
            $user->ledTeam()->update(['team_lead_id' => null]);
            $this->revokeAccess->handle($user);
            UserAudit::record('deleted', $actor, $user);
            $user->delete();
        });
    }
}
