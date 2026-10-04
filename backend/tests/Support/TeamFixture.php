<?php

namespace Tests\Support;

use App\Models\Team;
use App\Models\User;
use App\Models\Workstation;

/**
 * A team with a team lead and sales executives, each sales executive on their own workstation.
 * Built by InteractsWithApi::makeTeamWithMembers().
 */
final class TeamFixture
{
    /**
     * @param  list<User>  $agents
     * @param  list<Workstation>  $stations
     */
    public function __construct(
        public readonly Team $team,
        public readonly User $teamLead,
        public readonly array $agents,
        public readonly array $stations,
    ) {}

    /**
     * The n-th sales executive (0-based).
     */
    public function agent(int $index = 0): User
    {
        return $this->agents[$index];
    }

    public function station(int $index = 0): Workstation
    {
        return $this->stations[$index];
    }
}
