<?php

namespace App\Http\Requests\Leads;

use App\Enums\LeadLostReason;
use App\Enums\LeadStage;
use App\Http\Requests\Concerns\AuthorizesChangeOrRequest;
use App\Http\Requests\Leads\Concerns\LeadRules;
use App\Models\Lead;
use App\Models\Order;
use App\Models\User;
use App\Rules\VisibleTo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;

/**
 * Kanban stage move. `lost` needs lost_reason; `won` needs the order (visible, same client).
 */
class MoveLeadStageRequest extends FormRequest
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
        $lead = $this->route('lead');
        $clientId = $lead instanceof Lead ? $lead->client_id : 0;

        return [
            'stage' => ['required', Rule::enum(LeadStage::class)],
            'lost_reason' => ['nullable', Rule::enum(LeadLostReason::class)],
            'lost_note' => ['nullable', 'string', 'max:2000'],
            'order_id' => [
                'required_if:stage,'.LeadStage::Won->value,
                'nullable',
                'integer',
                new VisibleTo(Order::class, $user, fn (Builder $q) => $q->where('client_id', $clientId)),
            ],
            'reason' => ['nullable', 'string', 'max:500'],
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
        $changes = Arr::except($this->validated(), ['reason']);

        if (($changes['stage'] ?? null) !== LeadStage::Won->value) {
            unset($changes['order_id']);
        }

        return $changes;
    }
}
