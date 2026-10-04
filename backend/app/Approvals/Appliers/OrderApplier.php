<?php

namespace App\Approvals\Appliers;

use App\Actions\Orders\DeleteOrder;
use App\Actions\Orders\UpdateOrder;
use App\Models\Order;
use Illuminate\Database\Eloquent\Model;

/**
 * Applies approved order changes through the same actions as the direct-write path, so the stored totals
 * are recalculated (and still checked against the payments) when a discount changes.
 */
class OrderApplier extends AttributeApplier
{
    public function __construct(
        private readonly UpdateOrder $updateOrder,
        private readonly DeleteOrder $deleteOrder,
    ) {}

    protected function model(): string
    {
        return Order::class;
    }

    protected function update(Model $record, array $changes, array $relations): Model
    {
        /** @var Order $record */
        return $this->updateOrder->handle($record, $changes);
    }

    protected function delete(Model $record): Model
    {
        /** @var Order $record */
        $this->deleteOrder->handle($record);

        return $record;
    }
}
