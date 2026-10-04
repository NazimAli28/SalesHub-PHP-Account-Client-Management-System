<?php

namespace Database\Factories;

use App\Enums\AccountStanding;
use App\Models\PlatformAccount;
use App\Models\Workstation;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PlatformAccount>
 */
class PlatformAccountFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $handle = Str::lower(fake()->unique()->userName());

        return [
            'email' => $handle.'@example.com',
            'email_password' => Str::password(14),
            'discord_email' => null,
            'discord_username' => $handle.fake()->numerify('##'),
            'discord_password' => Str::password(14),
            'discord_created_on' => fake()->dateTimeBetween('-2 years', '-6 months')->format('Y-m-d'),
            'recovery_email' => 'recovery.'.$handle.'@example.com',
            'recovery_phone' => fake()->numerify('+1555#######'),
            'phone_holder_name' => fake()->name(),
            'batch_date' => fake()->dateTimeBetween('-6 months', '-1 month')->format('Y-m-d'),
            'workstation_id' => null,
            'assigned_at' => null,
            'standing' => AccountStanding::Active,
            'standing_changed_at' => null,
            'notes' => null,
        ];
    }

    public function assigned(?Workstation $workstation = null): static
    {
        return $this->state(fn (array $attributes) => [
            'workstation_id' => $workstation?->getKey() ?? Workstation::factory(),
            'assigned_at' => now()->subDays(fake()->numberBetween(1, 90)),
        ]);
    }

    public function standing(AccountStanding $standing): static
    {
        return $this->state(fn (array $attributes) => [
            'standing' => $standing,
            'standing_changed_at' => $standing === AccountStanding::Active ? null : now()->subDays(fake()->numberBetween(1, 30)),
        ]);
    }
}
