<?php

namespace Database\Factories;

use App\Enums\ClientStatus;
use App\Models\Client;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Client>
 */
class ClientFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->name();

        return [
            'discord_username' => Str::lower(fake()->unique()->userName()).fake()->numerify('##'),
            'name' => $name,
            'email' => fake()->unique()->userName().'@example.com',
            'payment_name' => $name,
            'country' => fake()->randomElement(['US', 'GB', 'CA', 'AU', 'DE', 'NL', 'SE', 'BR', 'PH', 'IN']),
            'owner_id' => null,
            'status' => ClientStatus::Active,
            'nurturing_rating' => fake()->numberBetween(20, 95),
            'next_upsell_plan' => null,
            'expected_upsell_on' => null,
            'lost_note' => null,
            'notes' => null,
        ];
    }
}
