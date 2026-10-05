<?php

namespace Database\Factories;

use App\Enums\ServiceCategory;
use App\Models\Service;
use Database\Seeders\Support\DemoText;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Service>
 */
class ServiceFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // Faker types words() as array|string; with $asText = true it always returns a string.
        /** @var string $words */
        $words = fake()->unique()->words(3, true);
        $name = Str::title($words);

        return [
            'name' => $name,
            'slug' => Str::slug($name),
            'category' => fake()->randomElement(ServiceCategory::cases()),
            'description' => DemoText::serviceDescription(),
            'base_price_cents' => fake()->numberBetween(15, 400) * 100,
            'currency' => 'USD',
            'is_active' => true,
        ];
    }
}
