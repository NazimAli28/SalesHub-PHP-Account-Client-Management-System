<?php

namespace App\Http\Resources;

use App\Http\Resources\Summaries\OrderSummaryResource;
use App\Http\Resources\Summaries\PaymentSummaryResource;
use App\Models\Client;
use Illuminate\Http\Request;

/**
 * Client 360: the client plus its (visible) leads, orders with balances, upcoming and overdue payments, and counts.
 * Built by ClientController::show, which sets the `leads`, `orders`, `upcomingPayments` and `overduePayments` relations.
 *
 * @mixin Client
 */
class ClientDetailResource extends ClientResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            'counts' => [
                'leads' => (int) ($this->resource->getAttribute('leads_count') ?? 0),
                'orders' => (int) ($this->resource->getAttribute('orders_count') ?? 0),
                'open_orders' => (int) ($this->resource->getAttribute('open_orders_count') ?? 0),
                'overdue_payments' => (int) ($this->resource->getAttribute('overdue_payments_count') ?? 0),
            ],
            'orders' => OrderSummaryResource::collection($this->whenLoaded('orders')),
            'upcoming_payments' => PaymentSummaryResource::collection($this->whenLoaded('upcomingPayments')),
            'overdue_payments' => PaymentSummaryResource::collection($this->whenLoaded('overduePayments')),
        ];
    }
}
