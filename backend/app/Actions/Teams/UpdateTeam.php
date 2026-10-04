<?php

namespace App\Actions\Teams;

use App\Models\Team;

class UpdateTeam
{
    /**
     * @param  array<string, mixed>  $changes
     */
    public function handle(Team $team, array $changes): Team
    {
        $team->fill($changes)->save();

        return $team;
    }
}
