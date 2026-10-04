<?php

namespace App\Http\Requests\Leads;

use App\Http\Requests\Concerns\AuthorizesChangeOrRequest;
use App\Http\Requests\Leads\Concerns\LeadRules;
use App\Models\Client;
use App\Models\Lead;
use App\Models\User;
use App\Rules\VisibleTo;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;

/**
 * PUT and PATCH are both partial updates. Used for the direct write and for the approval payload alike,
 * so a queued change is validated exactly like a direct one.
 */
class UpdateLeadRequest extends FormRequest
{
    use AuthorizesChangeOrRequest, LeadRules;

    public function authorize(): bool
    {
        return $this->canChangeOrRequest('update', $this->route('lead'));
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        /** @var User $user */
        $user = $this->user();
        $attributes = $this->leadAttributeRules($user);

        return [
            'client_id' => ['sometimes', 'integer', new VisibleTo(Client::class, $user)],
            'owner_id' => ['prohibited'],
            'order_id' => ['prohibited'],
            'closer_id' => ['sometimes', ...$attributes['closer_id']],
            'platform_account_id' => ['sometimes', ...$attributes['platform_account_id']],
            'stage' => ['sometimes', ...$attributes['stage']],
            'contacted_on' => ['sometimes', ...$attributes['contacted_on']],
            'estimated_value_cents' => ['sometimes', ...$attributes['estimated_value_cents']],
            'currency' => ['sometimes', ...$attributes['currency']],
            'last_message' => ['sometimes', ...$attributes['last_message']],
            'next_follow_up_on' => ['sometimes', ...$attributes['next_follow_up_on']],
            'lost_reason' => ['sometimes', ...$attributes['lost_reason']],
            'lost_note' => ['sometimes', ...$attributes['lost_note']],
            'service_ids' => ['sometimes', ...$attributes['service_ids']],
            'service_ids.*' => $attributes['service_ids.*'],
            'reason' => $attributes['reason'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'owner_id.prohibited' => 'Use PATCH /api/leads/{lead}/owner to reassign a lead.',
            'order_id.prohibited' => 'The order is linked when the lead is won.',
        ];
    }

    /**
     * @return list<\Closure>
     */
    public function after(): array
    {
        $lead = $this->route('lead');

        return [$this->lostReasonCheck($lead instanceof Lead ? $lead : null)];
    }

    /**
     * @return array<string, mixed>
     */
    public function changes(): array
    {
        return Arr::except($this->validated(), ['service_ids', 'reason']);
    }
}
