<?php

namespace App\Actions\Payments;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Support\Money;
use Illuminate\Validation\ValidationException;

/**
 * The payment-sum invariant (data-model section 5): paid plus scheduled payments of an order never exceed
 * the order total, and every payment uses the order currency. Void payments do not count.
 */
class PaymentSchedule
{
    /**
     * Sum of the order's non-void payments, optionally leaving one out.
     */
    public function committedCents(Order $order, ?Payment $except = null): int
    {
        return (int) $order->payments()
            ->where('status', '!=', PaymentStatus::Void->value)
            ->when($except?->exists, fn ($q) => $q->whereKeyNot($except?->getKey()))
            ->sum('amount_cents');
    }

    /**
     * @throws ValidationException
     */
    public function ensureFits(Order $order, int $amountCents, string $currency, ?Payment $except = null): void
    {
        if ($currency !== $order->currency) {
            throw ValidationException::withMessages(['currency' => "Payments must use the order currency ({$order->currency})."]);
        }

        $committed = $this->committedCents($order, $except);

        if ($committed + $amountCents > $order->total_cents) {
            $room = max(0, $order->total_cents - $committed);

            throw ValidationException::withMessages([
                'amount_cents' => 'The scheduled payments would exceed the order total; at most '.Money::format($room, $order->currency).' can still be scheduled.',
            ]);
        }
    }

    /**
     * After a change to an order's total: existing payments must still fit.
     *
     * @throws ValidationException
     */
    public function ensureTotalCoversPayments(Order $order, string $field = 'discount_cents'): void
    {
        if (in_array($order->status, [OrderStatus::Cancelled, OrderStatus::Refunded], true)) {
            return;
        }

        if ($this->committedCents($order) > $order->total_cents) {
            throw ValidationException::withMessages([
                $field => 'The new order total would be lower than the payments already scheduled or paid.',
            ]);
        }
    }

    public function nextSequence(Order $order): int
    {
        return (int) Payment::withTrashed()->where('order_id', $order->id)->max('sequence') + 1;
    }
}
