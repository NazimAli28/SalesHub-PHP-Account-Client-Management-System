<?php

namespace App\Http\Resources\Summaries;

use App\Http\Resources\Concerns\FormatsApiValues;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Compact order reference with money position (total, paid, balance).
 *
 * @mixin Order
 */
class OrderSummaryResource extends JsonResource
{
    use FormatsApiValues;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_number' => $this->order_number,
            'type' => $this->enum($this->type),
            'status' => $this->enum($this->status),
            'client_id' => $this->client_id,
            'client' => ClientSummaryResource::make($this->whenLoaded('client')),
            'ordered_on' => $this->date($this->ordered_on),
            'total' => $this->money($this->total_cents, $this->currency),
            'amount_paid' => $this->money($this->paidCents(), $this->currency),
            'balance' => $this->money($this->balanceCents(), $this->currency),
            'overdue_payments_count' => $this->overduePaymentsCount(),
        ];
    }
}
