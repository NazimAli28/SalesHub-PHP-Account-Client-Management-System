<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Service;
use Database\Seeders\Support\DemoText;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrderItem>
 */
class OrderItemFactory extends Factory
{
    /**
     * line_total_cents is computed by the model when saving.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'service_id' => Service::factory(),
            'description' => fake()->boolean(40) ? DemoText::itemDescription() : null,
            'quantity' => fake()->numberBetween(1, 4),
            'unit_price_cents' => fake()->numberBetween(15, 200) * 100,
            'line_total_cents' => 0,
        ];
    }
}
