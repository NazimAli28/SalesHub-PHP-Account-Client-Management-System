<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\FormatsApiValues;
use App\Http\Resources\Summaries\OrderSummaryResource;
use App\Http\Resources\Summaries\UserSummaryResource;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * `is_overdue` is derived (scheduled and past its due date).
 *
 * @mixin Payment
 */
class PaymentResource extends JsonResource
{
    use FormatsApiValues;

    /**
     * Relations the controller always eager-loads, so `pending_change` is present.
     */
    public const DEFAULT_WITH = ['pendingApproval.requester'];

    /**
     * Whitelisted `?include=` values (relation names).
     */
    public const INCLUDES = ['order', 'order.client', 'recordedBy'];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_id' => $this->order_id,
            'sequence' => $this->sequence,
            'amount' => $this->money($this->amount_cents, $this->currency),
            'currency' => $this->currency,
            'due_date' => $this->date($this->due_date),
            'status' => $this->enum($this->status),
            'is_overdue' => $this->is_overdue,
            'paid_at' => $this->dateTime($this->paid_at),
            'method' => $this->enum($this->method),
            'reference' => $this->reference,
            'notes' => $this->notes,
            'recorded_by_id' => $this->recorded_by_id,
            'order' => OrderSummaryResource::make($this->whenLoaded('order')),
            'recorded_by' => UserSummaryResource::make($this->whenLoaded('recordedBy')),
            'pending_change' => $this->pendingChange(),
            'created_at' => $this->dateTime($this->created_at),
            'updated_at' => $this->dateTime($this->updated_at),
        ];
    }
}
