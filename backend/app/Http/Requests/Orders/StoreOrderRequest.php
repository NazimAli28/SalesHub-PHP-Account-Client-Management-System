<?php

namespace App\Http\Requests\Orders;

use App\Http\Requests\Concerns\ResolvesActor;
use App\Http\Requests\Orders\Concerns\OrderRules;
use App\Models\Client;
use App\Models\Lead;
use App\Models\Order;
use App\Models\Service;
use App\Models\User;
use App\Rules\VisibleTo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;
use Illuminate\Validation\Validator;

/**
 * Creating an order is always a direct write (sales executives included). Items are required; an item without
 * `unit_price_cents` uses the service base price. An optional `lead_id` marks that lead as won.
 */
class StoreOrderRequest extends FormRequest
{
    use OrderRules, ResolvesActor;

    public function authorize(): bool
    {
        return (bool) $this->user()?->can('create', Order::class);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $user = $this->actor();
        $rules = $this->orderAttributeRules($user);
        $item = $this->itemRules();

        return [
            'client_id' => ['required', 'integer', new VisibleTo(Client::class, $user)],
            'owner_id' => ['nullable', 'integer', new VisibleTo(User::class, $user, fn (Builder $q) => $q->where('is_active', true))],
            'closer_id' => $rules['closer_id'],
            'platform_account_id' => $rules['platform_account_id'],
            'parent_order_id' => ['nullable', 'integer', new VisibleTo(Order::class, $user)],
            'lead_id' => ['nullable', 'integer', new VisibleTo(Lead::class, $user)],
            'status' => ['sometimes', ...$rules['status']],
            'currency' => ['sometimes', 'string', 'regex:/^[A-Z]{3}$/'],
            'discount_cents' => ['sometimes', ...$rules['discount_cents']],
            'ordered_on' => ['sometimes', ...$rules['ordered_on']],
            'notes' => $rules['notes'],
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.service_id' => ['required', ...$item['service_id']],
            'items.*.quantity' => ['sometimes', ...$item['quantity']],
            'items.*.unit_price_cents' => $item['unit_price_cents'],
            'items.*.description' => $item['description'],
        ];
    }

    /**
     * @return list<\Closure>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $clientId = (int) $this->input('client_id');

            $parent = $this->input('parent_order_id');
            if ($parent !== null && Order::query()->whereKey($parent)->value('client_id') !== $clientId) {
                $validator->errors()->add('parent_order_id', 'The parent order must belong to the same client.');
            }

            $lead = $this->lead();
            if ($lead !== null && $lead->client_id !== $clientId) {
                $validator->errors()->add('lead_id', 'The lead must belong to the same client.');
            } elseif ($lead !== null && $lead->order_id !== null) {
                $validator->errors()->add('lead_id', 'The lead already has an order.');
            }

            $discount = (int) $this->input('discount_cents', 0);
            if ($discount > $this->subtotalCents()) {
                $validator->errors()->add('discount_cents', 'The discount cannot exceed the order subtotal.');
            }
        }];
    }

    /**
     * @return array<string, mixed>
     */
    public function orderAttributes(): array
    {
        return Arr::except($this->validated(), ['items', 'lead_id']);
    }

    /**
     * @return list<array{service_id: int, quantity?: int, unit_price_cents?: int|null, description?: string|null}>
     */
    public function items(): array
    {
        /** @var list<array{service_id: int, quantity?: int, unit_price_cents?: int|null, description?: string|null}> */
        return array_values($this->validated('items', []));
    }

    public function lead(): ?Lead
    {
        $id = $this->input('lead_id');

        return $id === null ? null : Lead::query()->find($id);
    }

    private function subtotalCents(): int
    {
        $prices = Service::query()->whereKey(array_column($this->items(), 'service_id'))->pluck('base_price_cents', 'id');

        return (int) collect($this->items())->sum(
            fn (array $item): int => ($item['quantity'] ?? 1) * ($item['unit_price_cents'] ?? $prices->get($item['service_id'], 0)),
        );
    }
}
