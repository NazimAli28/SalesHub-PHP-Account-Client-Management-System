<?php

namespace App\Actions\Payments;

use App\Models\Payment;

class DeletePayment
{
    /**
     * Soft delete; the sequence number stays reserved.
     */
    public function handle(Payment $payment): void
    {
        $payment->delete();
    }
}
