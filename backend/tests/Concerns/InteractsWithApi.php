<?php

namespace Tests\Concerns;

use App\Enums\RoleName;
use App\Models\Team;
use App\Models\User;
use App\Models\Workstation;
use Tests\Support\TeamFixture;

/**
 * Helpers for module API tests (tests/Feature/Api). Requests use the plain JSON helpers
 * (getJson, postJson, ...) authenticated through the session guard that Sanctum checks.
 */
trait InteractsWithApi
{
    use InteractsWithSpa;

    /**
     * Authenticate the following requests as this user (safe to call again to switch users mid-test).
     */
    public function actingAsUser(User $user): User
    {
        app('auth')->forgetGuards();
        $this->actingAs($user, 'web');

        return $user;
    }

    /**
     * Create a user with the role (and optional attributes such as team_id) and act as them.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function actingAsRole(RoleName|string $role, array $attributes = []): User
    {
        $role = $role instanceof RoleName ? $role : RoleName::from($role);

        return $this->actingAsUser($this->makeUser($role, $attributes));
    }

    /**
     * A team with a team lead and `$agents` sales executives, each on their own workstation of the team.
     */
    public function makeTeamWithMembers(int $agents = 2): TeamFixture
    {
        $team = Team::factory()->create();
        $teamLead = $this->makeStaff(RoleName::TeamLead, $team);
        $team->update(['team_lead_id' => $teamLead->id]);

        $stations = [];
        $members = [];
        for ($i = 0; $i < $agents; $i++) {
            $stations[] = $station = Workstation::factory()->create(['team_id' => $team->id]);
            $members[] = $this->makeStaff(RoleName::SalesExecutive, $team, $station);
        }

        return new TeamFixture($team, $teamLead, $members, $stations);
    }
}
