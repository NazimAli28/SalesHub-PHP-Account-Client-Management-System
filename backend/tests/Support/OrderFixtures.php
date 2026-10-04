<?php

namespace Tests\Support;

use App\Actions\Orders\RecalculateOrderTotals;
use App\Models\Client;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;

/**
 * Order builders for the Orders / Payments / Clients API tests.
 */
final class OrderFixtures
{
    /**
     * An order owned by `$owner` (team snapshot included) with one item worth `$totalCents`, totals stored.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function make(User $owner, int $totalCents = 100000, array $attributes = []): Order
    {
        $order = Order::factory()->create([
            'owner_id' => $owner->id,
            'team_id' => $owner->team_id,
            'client_id' => Client::factory()->create(['owner_id' => $owner->id])->id,
            ...$attributes,
        ]);
        OrderItem::factory()->create(['order_id' => $order->id, 'quantity' => 1, 'unit_price_cents' => $totalCents]);

        return app(RecalculateOrderTotals::class)->handle($order);
    }
}
