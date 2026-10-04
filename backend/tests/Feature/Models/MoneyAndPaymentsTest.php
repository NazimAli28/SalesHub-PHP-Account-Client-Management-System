<?php

use App\Actions\Orders\RecalculateOrderTotals;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Service;

it('computes line totals from quantity and unit price', function () {
    $item = OrderItem::factory()->create(['quantity' => 3, 'unit_price_cents' => 1500]);

    expect($item->line_total_cents)->toBe(4500);
});

it('recalculates stored order totals from items and discount', function () {
    $order = Order::factory()->create(['discount_cents' => 1000]);
    $service = Service::factory()->create();
    OrderItem::factory()->create(['order_id' => $order->id, 'service_id' => $service->id, 'quantity' => 2, 'unit_price_cents' => 5000]);
    OrderItem::factory()->create(['order_id' => $order->id, 'service_id' => $service->id, 'quantity' => 1, 'unit_price_cents' => 2500]);

    app(RecalculateOrderTotals::class)->handle($order);

    $order->refresh();
    expect($order->subtotal_cents)->toBe(12500)
        ->and($order->discount_cents)->toBe(1000)
        ->and($order->total_cents)->toBe(11500);
});

it('never lets the total go negative', function () {
    $order = Order::factory()->withItems(1)->create();
    $order->update(['discount_cents' => $order->subtotal_cents + 5000]);

    app(RecalculateOrderTotals::class)->handle($order);

    expect($order->refresh()->total_cents)->toBe(0)
        ->and($order->discount_cents)->toBe($order->subtotal_cents);
});

it('builds totals through the order factory item state', function () {
    $order = Order::factory()->withItems(3)->create();

    expect($order->items)->toHaveCount(3)
        ->and($order->total_cents)->toBe((int) $order->items->sum('line_total_cents'));
});

it('derives paid amount and balance from paid payments only', function () {
    $order = Order::factory()->create(['total_cents' => 10000]);
    Payment::factory()->paid()->create(['order_id' => $order->id, 'sequence' => 1, 'amount_cents' => 4000]);
    Payment::factory()->create(['order_id' => $order->id, 'sequence' => 2, 'amount_cents' => 4000]);
    Payment::factory()->create(['order_id' => $order->id, 'sequence' => 3, 'amount_cents' => 2000, 'status' => PaymentStatus::Void]);

    $loaded = Order::query()->withPaymentTotals()->findOrFail($order->id);

    expect($order->paidCents())->toBe(4000)
        ->and($order->balanceCents())->toBe(6000)
        ->and($loaded->paidCents())->toBe(4000)
        ->and($loaded->balanceCents())->toBe(6000);
});

it('derives overdue payments from status and due date', function () {
    $order = Order::factory()->create();
    $overdue = Payment::factory()->overdue()->create(['order_id' => $order->id, 'sequence' => 1]);
    $upcoming = Payment::factory()->create(['order_id' => $order->id, 'sequence' => 2, 'due_date' => today()->addDays(3)->toDateString()]);
    $today = Payment::factory()->create(['order_id' => $order->id, 'sequence' => 3, 'due_date' => today()->toDateString()]);
    $paidPast = Payment::factory()->paid()->create(['order_id' => $order->id, 'sequence' => 4]);

    $ids = Payment::overdue()->pluck('id')->all();

    expect($ids)->toBe([$overdue->id])
        ->and($overdue->is_overdue)->toBeTrue()
        ->and($upcoming->is_overdue)->toBeFalse()
        ->and($today->is_overdue)->toBeFalse()
        ->and($paidPast->is_overdue)->toBeFalse();
});

it('finds payments due within a date range inclusively', function () {
    $order = Order::factory()->create();
    $a = Payment::factory()->create(['order_id' => $order->id, 'sequence' => 1, 'due_date' => '2026-05-10']);
    $b = Payment::factory()->create(['order_id' => $order->id, 'sequence' => 2, 'due_date' => '2026-05-20']);
    Payment::factory()->create(['order_id' => $order->id, 'sequence' => 3, 'due_date' => '2026-06-01']);

    $ids = Payment::dueBetween('2026-05-10', '2026-05-20')->orderBy('sequence')->pluck('id')->all();

    expect($ids)->toBe([$a->id, $b->id]);
});
