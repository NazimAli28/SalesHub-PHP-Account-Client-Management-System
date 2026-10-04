<?php

namespace App\Actions\Teams;

use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CreateTeam
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(array $attributes): Team
    {
        return DB::transaction(function () use ($attributes): Team {
            $team = Team::query()->create($attributes);

            // The lead sits on the team they lead.
            if ($team->team_lead_id !== null) {
                User::query()->whereKey($team->team_lead_id)->update(['team_id' => $team->id]);
            }

            return $team;
        });
    }
}
