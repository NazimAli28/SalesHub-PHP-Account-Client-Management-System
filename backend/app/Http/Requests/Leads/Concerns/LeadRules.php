<?php

namespace App\Http\Requests\Leads\Concerns;

use App\Enums\LeadLostReason;
use App\Enums\LeadStage;
use App\Models\Lead;
use App\Models\PlatformAccount;
use App\Models\User;
use App\Rules\VisibleTo;
use App\Support\LocalToday;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Field rules shared by the lead Form Requests. Prefix each rule list with 'required', 'sometimes' or 'nullable'.
 */
trait LeadRules
{
    /**
     * Rules for the editable lead attributes (without presence rules).
     *
     * @return array<string, list<mixed>>
     */
    protected function leadAttributeRules(User $user): array
    {
        return [
            // Like the owner, the closer must be an active user the actor can see (not another team's staff).
            'closer_id' => ['nullable', 'integer', new VisibleTo(User::class, $user, fn (Builder $q) => $q->where('is_active', true))],
            'platform_account_id' => ['nullable', 'integer', new VisibleTo(PlatformAccount::class, $user)],
            // `won` is set when an order exists: through PATCH /leads/{id}/stage with order_id, or by the Orders module.
            'stage' => [Rule::enum(LeadStage::class)->except([LeadStage::Won])],
            'contacted_on' => ['date_format:Y-m-d', LocalToday::notFuture()],
            'estimated_value_cents' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'currency' => ['string', 'regex:/^[A-Z]{3}$/'],
            'last_message' => ['nullable', 'string', 'max:5000'],
            'next_follow_up_on' => ['nullable', 'date_format:Y-m-d'],
            'lost_reason' => ['nullable', Rule::enum(LeadLostReason::class)],
            'lost_note' => ['nullable', 'string', 'max:2000'],
            'service_ids' => ['array', 'max:50'],
            'service_ids.*' => ['integer', 'distinct', Rule::exists('services', 'id')->where('is_active', true)->whereNull('deleted_at')],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * After-validation hook: a lead that ends up `lost` must have a lost_reason.
     */
    protected function lostReasonCheck(?Lead $lead): Closure
    {
        return function (Validator $validator) use ($lead): void {
            $stage = $this->has('stage') ? $this->input('stage') : ($lead?->stage->value ?? LeadStage::New->value);
            $reason = $this->has('lost_reason') ? $this->input('lost_reason') : $lead?->lost_reason?->value;

            if ($stage === LeadStage::Lost->value && ($reason === null || $reason === '')) {
                $validator->errors()->add('lost_reason', 'A lost reason is required when the lead is lost.');
            }
        };
    }

    /**
     * @return list<int>|null
     */
    public function serviceIds(): ?array
    {
        if (! $this->has('service_ids')) {
            return null;
        }

        /** @var list<int|string> $ids */
        $ids = $this->validated('service_ids', []);

        return array_map('intval', $ids);
    }

    public function reason(): ?string
    {
        $reason = $this->validated('reason');

        return is_string($reason) ? $reason : null;
    }
}
