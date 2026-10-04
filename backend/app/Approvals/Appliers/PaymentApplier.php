<?php

namespace App\Approvals\Appliers;

use App\Actions\Payments\DeletePayment;
use App\Actions\Payments\UpdatePayment;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Model;

/**
 * Applies approved payment changes through the same actions as the direct-write path, which re-check the
 * currency and the order-total invariant at approval time.
 */
class PaymentApplier extends AttributeApplier
{
    public function __construct(
        private readonly UpdatePayment $updatePayment,
        private readonly DeletePayment $deletePayment,
    ) {}

    protected function model(): string
    {
        return Payment::class;
    }

    protected function update(Model $record, array $changes, array $relations): Model
    {
        /** @var Payment $record */
        return $this->updatePayment->handle($record, $changes);
    }

    protected function delete(Model $record): Model
    {
        /** @var Payment $record */
        $this->deletePayment->handle($record);

        return $record;
    }
}
