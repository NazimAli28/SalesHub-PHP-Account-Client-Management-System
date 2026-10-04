<?php

namespace Database\Factories;

use App\Enums\SocialPlatform;
use App\Models\PlatformAccount;
use App\Models\SocialAccount;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<SocialAccount>
 */
class SocialAccountFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'platform_account_id' => PlatformAccount::factory(),
            'platform' => fake()->randomElement(SocialPlatform::cases()),
            'username' => Str::lower(fake()->unique()->userName()).fake()->numerify('##'),
            'login_email' => fake()->unique()->safeEmail(),
            'password' => Str::password(14),
            'created_on' => fake()->dateTimeBetween('-1 year', '-1 month')->format('Y-m-d'),
            'is_in_use' => fake()->boolean(30),
        ];
    }
}
