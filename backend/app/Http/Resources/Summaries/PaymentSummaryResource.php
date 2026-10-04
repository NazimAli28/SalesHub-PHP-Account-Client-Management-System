<?php

namespace App\Http\Resources\Summaries;

use App\Http\Resources\Concerns\FormatsApiValues;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Compact installment reference (client 360 upcoming/overdue lists).
 *
 * @mixin Payment
 */
class PaymentSummaryResource extends JsonResource
{
    use FormatsApiValues;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_id' => $this->order_id,
            'order_number' => $this->whenLoaded('order', fn () => $this->order->order_number),
            'sequence' => $this->sequence,
            'amount' => $this->money($this->amount_cents, $this->currency),
            'due_date' => $this->date($this->due_date),
            'status' => $this->enum($this->status),
            'is_overdue' => $this->is_overdue,
        ];
    }
}
