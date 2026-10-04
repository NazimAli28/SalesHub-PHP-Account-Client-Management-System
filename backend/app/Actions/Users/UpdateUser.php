<?php

namespace App\Actions\Users;

use App\Enums\RoleName;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class UpdateUser
{
    public function __construct(private readonly RevokeUserAccess $revokeAccess) {}

    /**
     * @param  array<string, mixed>  $changes  Validated attributes (no role).
     */
    public function handle(User $actor, User $user, array $changes, ?RoleName $role = null): User
    {
        return DB::transaction(function () use ($actor, $user, $changes, $role): User {
            $teamChanged = array_key_exists('team_id', $changes) && $changes['team_id'] !== $user->team_id;

            // A seat belongs to a team: moving the user without naming a new seat clears it.
            if ($teamChanged && ! array_key_exists('workstation_id', $changes)) {
                $changes['workstation_id'] = null;
            }

            $passwordChanged = array_key_exists('password', $changes);

            $user->fill($changes)->save();

            $previousRoles = $user->getRoleNames()->values()->all();
            $roleChanged = $role !== null && $previousRoles !== [$role->value];

            if ($roleChanged) {
                $user->syncRoles([$role->value]);
                UserAudit::record('role_changed', $actor, $user, ['from' => $previousRoles, 'to' => [$role->value]]);
            }

            // Leaving the team, or the team lead role, ends the lead assignment.
            if ($teamChanged || ($roleChanged && $role->value !== RoleName::TeamLead->value)) {
                $user->ledTeam()->update(['team_lead_id' => null]);
            }

            if ($passwordChanged) {
                UserAudit::record('password_reset', $actor, $user);

                if (! $actor->is($user)) {
                    $this->revokeAccess->handle($user);
                }
            }

            return $user;
        });
    }
}
