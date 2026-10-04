<?php

namespace App\Actions\Orders;

use App\Models\Order;

/**
 * Recomputes the stored order totals from its items.
 * Call inside the same transaction that writes the items.
 */
class RecalculateOrderTotals
{
    public function handle(Order $order): Order
    {
        $subtotal = (int) $order->items()->sum('line_total_cents');
        $discount = min((int) $order->discount_cents, $subtotal);

        $order->forceFill([
            'subtotal_cents' => $subtotal,
            'discount_cents' => $discount,
            'total_cents' => max(0, $subtotal - $discount),
        ])->save();

        return $order;
    }
}
