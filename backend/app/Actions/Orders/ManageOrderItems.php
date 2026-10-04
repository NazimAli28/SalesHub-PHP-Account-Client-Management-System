<?php

namespace App\Actions\Orders;

use App\Actions\Payments\PaymentSchedule;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Service;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Item add / update / remove. Each call recalculates the stored order totals in the same transaction and
 * refuses a change that would push the total below the payments already scheduled or paid.
 */
class ManageOrderItems
{
    public function __construct(
        private readonly RecalculateOrderTotals $recalculate,
        private readonly PaymentSchedule $schedule,
    ) {}

    /**
     * @param  array{service_id: int, quantity?: int, unit_price_cents?: int|null, description?: string|null}  $data
     */
    public function add(Order $order, array $data): Order
    {
        return $this->write($order, function () use ($order, $data): void {
            $service = Service::query()->findOrFail($data['service_id']);

            $order->items()->create([
                'service_id' => $service->id,
                'description' => $data['description'] ?? null,
                'quantity' => $data['quantity'] ?? 1,
                'unit_price_cents' => $data['unit_price_cents'] ?? $service->base_price_cents,
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $changes  service_id, quantity, unit_price_cents, description
     */
    public function update(Order $order, OrderItem $item, array $changes): Order
    {
        return $this->write($order, function () use ($item, $changes): void {
            $item->fill($changes)->save();
        });
    }

    public function remove(Order $order, OrderItem $item): Order
    {
        if ($order->items()->count() <= 1) {
            throw ValidationException::withMessages(['item' => 'An order needs at least one item.']);
        }

        return $this->write($order, function () use ($item): void {
            $item->delete();
        });
    }

    /**
     * @param  callable(): void  $change
     */
    private function write(Order $order, callable $change): Order
    {
        if (in_array($order->status, [OrderStatus::Cancelled, OrderStatus::Refunded], true)) {
            throw ValidationException::withMessages(['order' => 'Items cannot be changed on a cancelled or refunded order.']);
        }

        return DB::transaction(function () use ($order, $change): Order {
            $change();
            $this->recalculate->handle($order);
            $this->schedule->ensureTotalCoversPayments($order, 'item');

            return $order;
        });
    }
}
