<?php

namespace App\Http\Requests\Orders;

use App\Http\Requests\Orders\Concerns\OrderRules;
use App\Models\Order;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/orders/{order}/items. Item edits are direct writes: only roles that may update the order.
 */
class StoreOrderItemRequest extends FormRequest
{
    use OrderRules;

    public function authorize(): bool
    {
        $order = $this->route('order');

        return $order instanceof Order && (bool) $this->user()?->can('update', $order);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $item = $this->itemRules();

        return [
            'service_id' => ['required', ...$item['service_id']],
            'quantity' => ['sometimes', ...$item['quantity']],
            'unit_price_cents' => $item['unit_price_cents'],
            'description' => $item['description'],
        ];
    }

    /**
     * @return array{service_id: int, quantity?: int, unit_price_cents?: int|null, description?: string|null}
     */
    public function itemData(): array
    {
        /** @var array{service_id: int, quantity?: int, unit_price_cents?: int|null, description?: string|null} */
        return $this->validated();
    }
}
