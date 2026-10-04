<?php

namespace App\Support;

use App\Models\Order;
use Carbon\CarbonInterface;

/**
 * Generates order numbers in the form SH-YYYY-NNNNN, sequential per year.
 * The unique index on orders.order_number is the final guard against races.
 */
class OrderNumberGenerator
{
    public static function next(?CarbonInterface $date = null): string
    {
        $year = ($date ?? now())->format('Y');
        $prefix = "SH-{$year}-";

        $last = Order::withTrashed()
            ->where('order_number', 'like', $prefix.'%')
            ->max('order_number');

        $sequence = is_string($last) ? ((int) substr($last, strlen($prefix))) + 1 : 1;

        return $prefix.str_pad((string) $sequence, 5, '0', STR_PAD_LEFT);
    }
}
