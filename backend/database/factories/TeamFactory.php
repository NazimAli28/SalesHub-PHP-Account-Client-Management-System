<?php

namespace Database\Factories;

use App\Enums\Shift;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Team>
 */
class TeamFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Unit '.fake()->numberBetween(1, 9).' '.ucfirst(fake()->unique()->lexify('??????')),
            'floor' => fake()->numberBetween(1, 5),
            'shift' => fake()->randomElement(Shift::cases()),
            'team_lead_id' => null,
        ];
    }
}
