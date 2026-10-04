<?php

namespace App\Actions\Teams;

use App\Models\Team;
use Illuminate\Validation\ValidationException;

class DeleteTeam
{
    /**
     * Soft delete. A team that still has members or workstations must be emptied first.
     */
    public function handle(Team $team): void
    {
        if ($team->members()->exists() || $team->workstations()->exists()) {
            throw ValidationException::withMessages([
                'team' => 'This team still has members or workstations. Move them to another team first.',
            ]);
        }

        $team->delete();
    }
}
