<?php

namespace App\Http\Requests\Orders\Concerns;

use App\Enums\OrderStatus;
use App\Models\PlatformAccount;
use App\Models\User;
use App\Rules\VisibleTo;
use Illuminate\Validation\Rule;

/**
 * Field rules shared by the order Form Requests. Prefix each rule list with 'required', 'sometimes' or 'nullable'.
 */
trait OrderRules
{
    /**
     * @return array<string, list<mixed>>
     */
    protected function orderAttributeRules(User $user): array
    {
        return [
            'closer_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('is_active', true)->whereNull('deleted_at')],
            'platform_account_id' => ['nullable', 'integer', new VisibleTo(PlatformAccount::class, $user)],
            'status' => [Rule::enum(OrderStatus::class)],
            'discount_cents' => ['integer', 'min:0', 'max:100000000'],
            'ordered_on' => ['date_format:Y-m-d', 'before_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * Rules for one order item, without presence rules.
     *
     * @return array<string, list<mixed>>
     */
    protected function itemRules(): array
    {
        return [
            'service_id' => ['integer', Rule::exists('services', 'id')->where('is_active', true)->whereNull('deleted_at')],
            'quantity' => ['integer', 'min:1', 'max:1000'],
            'unit_price_cents' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'description' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function reason(): ?string
    {
        $reason = $this->validated('reason');

        return is_string($reason) ? $reason : null;
    }
}
