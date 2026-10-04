<?php

namespace App\Policies;

use App\Enums\PaymentStatus;
use App\Enums\RoleName;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Policies\Concerns\ChecksVisibility;

class PaymentPolicy
{
    use ChecksVisibility;

    /**
     * Payments are visible through their order, so order permissions decide scope.
     */
    public function viewAny(User $user): bool
    {
        return $this->canViewAny($user, 'orders');
    }

    public function view(User $user, Payment $payment): bool
    {
        return $this->viewAny($user) && $this->canSee($user, $payment);
    }

    public function create(User $user, Order $order): bool
    {
        return $this->canOn($user, 'payments.create', $order);
    }

    /**
     * Paid payments can only be changed by support or admin.
     */
    public function update(User $user, Payment $payment): bool
    {
        if (! $this->canOn($user, 'payments.update', $payment)) {
            return false;
        }

        return $payment->status !== PaymentStatus::Paid
            || $user->hasAnyRole([RoleName::Admin->value, RoleName::Support->value]);
    }

    public function delete(User $user, Payment $payment): bool
    {
        return $this->canOn($user, 'payments.delete', $payment);
    }

    public function requestChange(User $user, Payment $payment): bool
    {
        return $this->canOn($user, 'payments.request-change', $payment);
    }
}
