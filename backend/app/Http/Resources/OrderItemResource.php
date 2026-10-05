<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\FormatsApiValues;
use App\Http\Resources\Summaries\ServiceSummaryResource;
use App\Models\OrderItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin OrderItem
 */
class OrderItemResource extends JsonResource
{
    use FormatsApiValues;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $currency = $this->relationLoaded('order') ? ($this->order === null ? 'USD' : $this->order->currency) : 'USD';

        return [
            'id' => $this->id,
            'order_id' => $this->order_id,
            'service_id' => $this->service_id,
            'description' => $this->description,
            'quantity' => $this->quantity,
            'unit_price' => $this->money($this->unit_price_cents, $currency),
            'line_total' => $this->money($this->line_total_cents, $currency),
            'service' => ServiceSummaryResource::make($this->whenLoaded('service')),
        ];
    }
}
