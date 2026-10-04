<?php

namespace App\Actions\Payments;

use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;

class CreatePayment
{
    public function __construct(private readonly PaymentSchedule $schedule) {}

    /**
     * Schedules the next installment of an order. The sequence is assigned here; the amount must fit the order total.
     *
     * @param  array<string, mixed>  $data  Validated: amount_cents, due_date, currency?, method?, reference?, notes?
     */
    public function handle(Order $order, array $data): Payment
    {
        return DB::transaction(function () use ($order, $data): Payment {
            $currency = $data['currency'] ?? $order->currency;
            $this->schedule->ensureFits($order, (int) $data['amount_cents'], $currency);

            return Payment::query()->create([
                ...$data,
                'order_id' => $order->id,
                'sequence' => $this->schedule->nextSequence($order),
                'currency' => $currency,
                'status' => PaymentStatus::Scheduled->value,
            ]);
        });
    }
}
