<?php

namespace Database\Seeders;

use App\Actions\Orders\RecalculateOrderTotals;
use App\Enums\LeadStage;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Lead;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Service;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\Support\DemoText;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;

class OrderSeeder extends Seeder
{
    /**
     * @var Collection<int, Service>
     */
    private Collection $services;

    /**
     * 180 orders: 100 fresh orders from won leads and 80 upsells of earlier orders,
     * each with 1-4 line items and 1-3 payment installments.
     */
    public function run(): void
    {
        $this->services = Service::query()->where('slug', '!=', 'custom')->get();
        $users = User::query()->get()->keyBy('id');

        $wonLeads = Lead::query()->where('stage', LeadStage::Won->value)->with('services')->orderBy('id')->get();

        foreach ($wonLeads as $lead) {
            $owner = $users[$lead->owner_id];
            $order = $this->createOrder([
                'client_id' => $lead->client_id,
                'owner_id' => $lead->owner_id,
                'closer_id' => $lead->closer_id,
                'team_id' => $owner->team_id,
                'platform_account_id' => $lead->platform_account_id,
                'type' => OrderType::Fresh,
                'ordered_on' => $lead->stage_changed_at->toDateString(),
            ], $lead->services);

            $lead->update(['order_id' => $order->id]);
        }

        $parents = Order::query()
            ->where('ordered_on', '<=', now()->subDays(10)->toDateString())
            ->where('status', '!=', OrderStatus::Cancelled->value)
            ->get();

        for ($i = 0; $i < 80; $i++) {
            /** @var Order $parent */
            $parent = fake()->randomElement($parents->all());
            $orderedOn = CarbonImmutable::parse($parent->ordered_on)->addDays(fake()->numberBetween(7, 60));

            if ($orderedOn->isFuture()) {
                $orderedOn = CarbonImmutable::today()->subDays(fake()->numberBetween(0, 5));
            }

            $this->createOrder([
                'client_id' => $parent->client_id,
                'owner_id' => $parent->owner_id,
                'team_id' => $parent->team_id,
                'platform_account_id' => $parent->platform_account_id,
                'parent_order_id' => $parent->id,
                'type' => OrderType::Upsell,
                'ordered_on' => $orderedOn->toDateString(),
            ], collect(), 2);
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  Collection<int, Service>  $preferred
     */
    private function createOrder(array $attributes, Collection $preferred, int $maxItems = 4): Order
    {
        $order = Order::create($attributes + [
            'status' => OrderStatus::PendingPayment,
            'currency' => 'USD',
        ]);

        $count = fake()->numberBetween(1, $maxItems);
        $picked = $preferred->take($count)->concat(
            $this->services->shuffle()->take($count),
        )->unique('id')->take($count);

        foreach ($picked as $service) {
            OrderItem::create([
                'order_id' => $order->id,
                'service_id' => $service->id,
                'description' => fake()->boolean(25) ? DemoText::itemDescription() : null,
                'quantity' => $service->slug === 'emote-each' ? fake()->numberBetween(2, 5) : 1,
                'unit_price_cents' => $service->base_price_cents,
            ]);
        }

        $subtotal = (int) $order->items()->sum('line_total_cents');
        if (fake()->boolean(15)) {
            $order->discount_cents = (int) round($subtotal * fake()->randomElement([0.05, 0.1]) / 100) * 100;
        }
        app(RecalculateOrderTotals::class)->handle($order);

        $this->createPayments($order);
        $this->settleStatus($order);

        return $order;
    }

    private function createPayments(Order $order): void
    {
        $roll = fake()->numberBetween(1, 100);
        $installments = $roll <= 35 ? 1 : ($roll <= 80 ? 2 : 3);
        $total = (int) $order->total_cents;
        $base = intdiv($total, $installments);
        $orderedOn = CarbonImmutable::parse($order->ordered_on);
        $cancelled = fake()->boolean(4);

        for ($sequence = 1; $sequence <= $installments; $sequence++) {
            $amount = $sequence === $installments ? $total - $base * ($installments - 1) : $base;
            $due = $orderedOn->addDays(14 * ($sequence - 1));
            $isPast = ! $due->isFuture();
            $paid = $isPast && ! fake()->boolean(8);

            $status = match (true) {
                $cancelled => PaymentStatus::Void,
                $paid => PaymentStatus::Paid,
                default => PaymentStatus::Scheduled,
            };

            Payment::create([
                'order_id' => $order->id,
                'sequence' => $sequence,
                'amount_cents' => $amount,
                'currency' => $order->currency,
                'due_date' => $due->toDateString(),
                'status' => $status,
                'paid_at' => $status === PaymentStatus::Paid ? $due->addDays(fake()->numberBetween(0, 2))->min(now()) : null,
                'method' => $status === PaymentStatus::Paid ? fake()->randomElement(PaymentMethod::cases()) : null,
                'reference' => $status === PaymentStatus::Paid ? strtoupper(fake()->bothify('TX-########')) : null,
                'recorded_by_id' => $status === PaymentStatus::Paid ? $order->owner_id : null,
            ]);
        }
    }

    private function settleStatus(Order $order): void
    {
        $payments = $order->payments()->get();
        $paid = $payments->where('status', PaymentStatus::Paid)->count();
        $age = CarbonImmutable::parse($order->ordered_on)->diffInDays(now());

        $status = match (true) {
            $payments->every(fn (Payment $p) => $p->status === PaymentStatus::Void) => OrderStatus::Cancelled,
            $paid === 0 => OrderStatus::PendingPayment,
            $paid === $payments->count() && $age > 21 => fake()->boolean(8) ? OrderStatus::Refunded : OrderStatus::Completed,
            $age > 10 => OrderStatus::Delivered,
            default => OrderStatus::InProgress,
        };

        $order->status = $status;

        if (in_array($status, [OrderStatus::Delivered, OrderStatus::Completed], true)) {
            $order->delivered_at = $order->ordered_on->copy()->addDays(fake()->numberBetween(3, 9))->min(now());
        }

        $order->save();
    }
}
