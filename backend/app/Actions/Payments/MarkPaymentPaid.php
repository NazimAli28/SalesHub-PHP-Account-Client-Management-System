<?php

namespace App\Actions\Payments;

use App\Enums\PaymentStatus;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Builds the change set for "this installment was paid". It is applied (or queued) like any other payment
 * update, so a sales executive's mark-paid goes to approval.
 */
class MarkPaymentPaid
{
    /**
     * @param  array<string, mixed>  $input  Validated: paid_at?, method?, reference?, notes?
     * @return array<string, mixed>
     */
    public function changes(User $actor, array $input): array
    {
        $paidAt = isset($input['paid_at']) ? CarbonImmutable::parse((string) $input['paid_at']) : CarbonImmutable::now();

        return [
            ...array_intersect_key($input, array_flip(['method', 'reference', 'notes'])),
            'status' => PaymentStatus::Paid->value,
            'paid_at' => $paidAt->utc()->toDateTimeString(),
            'recorded_by_id' => $actor->id,
        ];
    }
}
