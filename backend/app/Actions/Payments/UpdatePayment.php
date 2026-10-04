<?php

namespace App\Actions\Payments;

use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;

/**
 * The single write path for payment changes: used by the controllers (direct update, mark-paid) and by
 * PaymentApplier (approved request), so the order-total invariant holds on every path.
 */
class UpdatePayment
{
    public function __construct(private readonly PaymentSchedule $schedule) {}

    /**
     * Pure: leaving `paid` clears the payment timestamp and recorder.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function prepare(Payment $payment, array $data): array
    {
        $status = isset($data['status']) ? PaymentStatus::from((string) $data['status']) : $payment->status;

        if ($status !== PaymentStatus::Paid && $payment->paid_at !== null) {
            $data['paid_at'] = null;
            $data['recorded_by_id'] = null;
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $changes  Output of prepare().
     */
    public function handle(Payment $payment, array $changes): Payment
    {
        return DB::transaction(function () use ($payment, $changes): Payment {
            $payment->fill($changes);

            if ($payment->status !== PaymentStatus::Void) {
                $order = Order::query()->findOrFail($payment->order_id);
                $this->schedule->ensureFits($order, $payment->amount_cents, $payment->currency, $payment);
            }

            $payment->save();

            return $payment;
        });
    }
}
