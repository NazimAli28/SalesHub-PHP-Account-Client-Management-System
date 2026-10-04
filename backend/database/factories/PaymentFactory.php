<?php

namespace Database\Factories;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'sequence' => 1,
            'amount_cents' => fake()->numberBetween(20, 300) * 100,
            'currency' => fn (array $attributes) => Order::query()->find($attributes['order_id'])->currency ?? 'USD',
            'due_date' => now()->addDays(fake()->numberBetween(1, 30))->toDateString(),
            'status' => PaymentStatus::Scheduled,
            'paid_at' => null,
            'method' => null,
            'reference' => null,
            'recorded_by_id' => null,
            'notes' => null,
        ];
    }

    public function paid(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => PaymentStatus::Paid,
            'due_date' => now()->subDays(fake()->numberBetween(1, 30))->toDateString(),
            'paid_at' => now()->subDays(fake()->numberBetween(0, 30)),
            'method' => fake()->randomElement(PaymentMethod::cases()),
            'reference' => strtoupper(fake()->bothify('TX-########')),
        ]);
    }

    public function overdue(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => PaymentStatus::Scheduled,
            'due_date' => now()->subDays(fake()->numberBetween(2, 25))->toDateString(),
            'paid_at' => null,
        ]);
    }
}
