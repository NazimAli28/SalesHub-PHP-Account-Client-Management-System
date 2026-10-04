<?php

namespace Database\Factories;

use App\Models\Team;
use App\Models\Workstation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Workstation>
 */
class WorkstationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => 'PC-'.fake()->unique()->numerify('####'),
            'team_id' => Team::factory(),
            'label' => null,
            'is_active' => true,
        ];
    }
}
