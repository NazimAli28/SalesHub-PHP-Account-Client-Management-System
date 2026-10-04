<?php

namespace App\Actions\Orders;

use App\Enums\LeadStage;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Models\Lead;
use App\Models\Order;
use App\Models\Service;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CreateOrder
{
    public function __construct(private readonly RecalculateOrderTotals $recalculate) {}

    /**
     * Creates the order, its items and the stored totals in one transaction. The owner defaults to the acting user,
     * `team_id` snapshots the owner's team, the type is `upsell` when a parent order is given. A linked lead moves
     * to `won` and points at the new order.
     *
     * @param  array<string, mixed>  $data  Validated order attributes.
     * @param  list<array{service_id: int, quantity?: int, unit_price_cents?: int|null, description?: string|null}>  $items
     */
    public function handle(User $actor, array $data, array $items, ?Lead $lead = null): Order
    {
        return DB::transaction(function () use ($actor, $data, $items, $lead): Order {
            $data['owner_id'] ??= $actor->id;
            $data['team_id'] = User::query()->whereKey($data['owner_id'])->value('team_id');
            $data['type'] = ($data['parent_order_id'] ?? null) !== null ? OrderType::Upsell->value : OrderType::Fresh->value;
            $data['status'] ??= OrderStatus::PendingPayment->value;
            $data['currency'] ??= 'USD';
            $data['ordered_on'] ??= today()->toDateString();
            $data['discount_cents'] ??= 0;

            $order = Order::query()->create($data);

            $services = Service::query()->whereKey(array_column($items, 'service_id'))->get()->keyBy('id');
            foreach ($items as $item) {
                $service = $services->get($item['service_id']);

                $order->items()->create([
                    'service_id' => $item['service_id'],
                    'description' => $item['description'] ?? null,
                    'quantity' => $item['quantity'] ?? 1,
                    'unit_price_cents' => $item['unit_price_cents'] ?? $service->base_price_cents,
                ]);
            }

            $this->recalculate->handle($order);

            if ($lead !== null) {
                $lead->forceFill(['stage' => LeadStage::Won, 'order_id' => $order->id, 'next_follow_up_on' => null])->save();
            }

            return $order;
        });
    }
}
