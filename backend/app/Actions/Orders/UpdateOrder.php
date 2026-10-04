<?php

namespace App\Actions\Orders;

use App\Actions\Payments\PaymentSchedule;
use App\Enums\OrderStatus;
use App\Models\Order;
use Illuminate\Support\Facades\DB;

/**
 * The single write path for order changes: used by the controller (direct update) and by OrderApplier
 * (approved request). Totals are recalculated in the same transaction.
 */
class UpdateOrder
{
    public function __construct(
        private readonly RecalculateOrderTotals $recalculate,
        private readonly PaymentSchedule $schedule,
    ) {}

    /**
     * Turns validated input into the full change set: a delivered order gets a delivery time.
     * Pure: does not touch the model.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function prepare(Order $order, array $data): array
    {
        $status = isset($data['status']) ? OrderStatus::from((string) $data['status']) : $order->status;

        if ($status === OrderStatus::Delivered && $order->delivered_at === null && ! array_key_exists('delivered_at', $data)) {
            $data['delivered_at'] = now()->toDateTimeString();
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $changes  Output of prepare().
     */
    public function handle(Order $order, array $changes): Order
    {
        return DB::transaction(function () use ($order, $changes): Order {
            $order->fill($changes)->save();

            $this->recalculate->handle($order);
            $this->schedule->ensureTotalCoversPayments($order);

            return $order;
        });
    }
}
