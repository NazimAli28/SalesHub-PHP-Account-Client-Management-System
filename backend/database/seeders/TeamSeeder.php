<?php

namespace Database\Seeders;

use App\Enums\Shift;
use App\Models\Team;
use Illuminate\Database\Seeder;

class TeamSeeder extends Seeder
{
    public function run(): void
    {
        $teams = [
            ['Unit 1 Alpha', 3, Shift::Evening],
            ['Unit 1 Bravo', 3, Shift::Night],
            ['Unit 2 Charlie', 4, Shift::Evening],
            ['Unit 2 Delta', 4, Shift::Night],
        ];

        foreach ($teams as [$name, $floor, $shift]) {
            Team::factory()->create(['name' => $name, 'floor' => $floor, 'shift' => $shift]);
        }
    }
}
