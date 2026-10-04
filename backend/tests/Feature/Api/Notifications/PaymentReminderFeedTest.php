<?php

use App\Enums\RoleName;
use App\Models\Order;
use App\Models\Payment;

it('shows a payment reminder in the owner notification feed', function () {
    $owner = $this->actingAsRole(RoleName::SalesExecutive);
    $order = Order::factory()->create(['owner_id' => $owner->id]);
    Payment::factory()->create(['order_id' => $order->id, 'due_date' => today()->toDateString()]);

    $this->artisan('payments:send-reminders')->assertSuccessful();

    $this->getJson('/api/notifications')->assertOk()
        ->assertJsonPath('data.0.type', 'PaymentDueReminder')
        ->assertJsonPath('data.0.data.order_number', $order->order_number)
        ->assertJsonPath('data.0.data.order_id', $order->id);
});
