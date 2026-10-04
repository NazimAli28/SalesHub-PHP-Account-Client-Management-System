<?php

namespace App\Actions\Orders;

use App\Models\Order;

class DeleteOrder
{
    /**
     * Soft delete; items and payments stay so a restore brings them back.
     */
    public function handle(Order $order): void
    {
        $order->delete();
    }
}
