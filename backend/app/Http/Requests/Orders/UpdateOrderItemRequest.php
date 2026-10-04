<?php

namespace App\Http\Requests\Orders;

use App\Http\Requests\Orders\Concerns\OrderRules;
use App\Models\Order;
use Illuminate\Foundation\Http\FormRequest;

/**
 * PUT/PATCH /api/orders/{order}/items/{item}: partial update, direct write only.
 * `unit_price_cents` null is not allowed here (the price is already stored).
 */
class UpdateOrderItemRequest extends FormRequest
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
            'service_id' => ['sometimes', ...$item['service_id']],
            'quantity' => ['sometimes', ...$item['quantity']],
            'unit_price_cents' => ['sometimes', 'required', ...$item['unit_price_cents']],
            'description' => ['sometimes', ...$item['description']],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function changes(): array
    {
        return $this->validated();
    }
}
