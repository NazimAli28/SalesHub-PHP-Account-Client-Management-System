<?php

namespace Database\Seeders;

use App\Models\Team;
use App\Models\Workstation;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Seeder;

class WorkstationSeeder extends Seeder
{
    /**
     * PC-01 to PC-12, three per team.
     */
    public function run(): void
    {
        $teams = Team::query()->orderBy('id')->get();

        for ($i = 1; $i <= 12; $i++) {
            Workstation::factory()->create([
                'code' => sprintf('PC-%02d', $i),
                'team_id' => ($teams->get(intdiv($i - 1, 3)) ?? throw new ModelNotFoundException)->id,
                'label' => 'Desk '.$i,
            ]);
        }
    }
}
