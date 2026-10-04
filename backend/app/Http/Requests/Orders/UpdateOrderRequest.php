<?php

namespace App\Http\Requests\Orders;

use App\Http\Requests\Concerns\AuthorizesChangeOrRequest;
use App\Http\Requests\Orders\Concerns\OrderRules;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;
use Illuminate\Validation\Validator;

/**
 * PUT and PATCH are both partial updates. Used for the direct write and for the approval payload alike.
 * Items are edited through /orders/{order}/items; client, owner, currency and the totals never change here.
 */
class UpdateOrderRequest extends FormRequest
{
    use AuthorizesChangeOrRequest, OrderRules;

    public function authorize(): bool
    {
        return $this->canChangeOrRequest('update', $this->route('order'));
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        /** @var User $user */
        $user = $this->user();
        $rules = $this->orderAttributeRules($user);

        return [
            'client_id' => ['prohibited'],
            'owner_id' => ['prohibited'],
            'currency' => ['prohibited'],
            'type' => ['prohibited'],
            'parent_order_id' => ['prohibited'],
            'team_id' => ['prohibited'],
            'items' => ['prohibited'],
            'subtotal_cents' => ['prohibited'],
            'total_cents' => ['prohibited'],
            'closer_id' => ['sometimes', ...$rules['closer_id']],
            'platform_account_id' => ['sometimes', ...$rules['platform_account_id']],
            'status' => ['sometimes', ...$rules['status']],
            'discount_cents' => ['sometimes', ...$rules['discount_cents']],
            'ordered_on' => ['sometimes', ...$rules['ordered_on']],
            'delivered_at' => ['sometimes', 'nullable', 'date'],
            'notes' => ['sometimes', ...$rules['notes']],
            'reason' => $rules['reason'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'items.prohibited' => 'Use /api/orders/{order}/items to add, change or remove items.',
            'subtotal_cents.prohibited' => 'Totals are calculated from the items and the discount.',
            'total_cents.prohibited' => 'Totals are calculated from the items and the discount.',
        ];
    }

    /**
     * @return list<\Closure>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $order = $this->route('order');

            if ($validator->errors()->isNotEmpty() || ! $order instanceof Order || ! $this->has('discount_cents')) {
                return;
            }

            if ((int) $this->input('discount_cents') > (int) $order->subtotal_cents) {
                $validator->errors()->add('discount_cents', 'The discount cannot exceed the order subtotal.');
            }
        }];
    }

    /**
     * @return array<string, mixed>
     */
    public function changes(): array
    {
        return Arr::except($this->validated(), ['reason']);
    }
}
