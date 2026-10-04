<?php

namespace Database\Factories;

use App\Enums\RoleName;
use App\Models\User;
use App\Support\TwoFactorAuthenticator;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The password hash shared by all factory users (hashed once for speed).
     */
    protected static ?string $password;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->firstName().' '.fake()->lastName(),
            'username' => Str::lower(Str::limit(fake()->unique()->userName(), 40, '')),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('Demo@12345'),
            'is_active' => true,
            'remember_token' => Str::random(10),
        ];
    }

    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    /**
     * Two-factor sign-in confirmed, with a random TOTP secret and eight recovery codes.
     */
    public function withTwoFactor(): static
    {
        return $this->state(function (array $attributes) {
            $twoFactor = new TwoFactorAuthenticator;

            return [
                'two_factor_secret' => $twoFactor->generateSecret(),
                'two_factor_recovery_codes' => $twoFactor->generateRecoveryCodes(),
                'two_factor_confirmed_at' => now(),
            ];
        });
    }

    public function admin(): static
    {
        return $this->withRole(RoleName::Admin);
    }

    public function support(): static
    {
        return $this->withRole(RoleName::Support);
    }

    public function teamLead(): static
    {
        return $this->withRole(RoleName::TeamLead);
    }

    public function salesExecutive(): static
    {
        return $this->withRole(RoleName::SalesExecutive);
    }

    protected function withRole(RoleName $role): static
    {
        return $this->afterCreating(function (User $user) use ($role): void {
            Role::findOrCreate($role->value, 'web');
            $user->assignRole($role->value);
        });
    }
}
