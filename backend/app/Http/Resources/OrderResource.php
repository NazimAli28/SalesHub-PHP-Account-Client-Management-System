<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\FormatsApiValues;
use App\Http\Resources\Summaries\ClientSummaryResource;
use App\Http\Resources\Summaries\OrderSummaryResource;
use App\Http\Resources\Summaries\PlatformAccountSummaryResource;
use App\Http\Resources\Summaries\UserSummaryResource;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * `amount_paid`, `balance` and `overdue_payments_count` are derived from payments (data-model section 5).
 *
 * @mixin Order
 */
class OrderResource extends JsonResource
{
    use FormatsApiValues;

    /**
     * Relations the controller always eager-loads, so `pending_change` is present.
     */
    public const DEFAULT_WITH = ['pendingApproval.requester'];

    /**
     * Whitelisted `?include=` values (relation names).
     */
    public const INCLUDES = ['client', 'owner', 'closer', 'team', 'platformAccount', 'parent', 'items', 'items.service', 'payments'];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        if ($this->resource->relationLoaded('items')) {
            // Items format their money with the order currency.
            $this->resource->items->each(fn ($item) => $item->setRelation('order', $this->resource));
        }

        return [
            'id' => $this->id,
            'order_number' => $this->order_number,
            'type' => $this->enum($this->type),
            'status' => $this->enum($this->status),
            'currency' => $this->currency,
            'subtotal' => $this->money($this->subtotal_cents, $this->currency),
            'discount' => $this->money($this->discount_cents, $this->currency),
            'total' => $this->money($this->total_cents, $this->currency),
            'amount_paid' => $this->money($this->paidCents(), $this->currency),
            'balance' => $this->money($this->balanceCents(), $this->currency),
            'overdue_payments_count' => $this->overduePaymentsCount(),
            'ordered_on' => $this->date($this->ordered_on),
            'delivered_at' => $this->dateTime($this->delivered_at),
            'notes' => $this->notes,
            'client_id' => $this->client_id,
            'owner_id' => $this->owner_id,
            'closer_id' => $this->closer_id,
            'team_id' => $this->team_id,
            'platform_account_id' => $this->platform_account_id,
            'parent_order_id' => $this->parent_order_id,
            'client' => ClientSummaryResource::make($this->whenLoaded('client')),
            'owner' => UserSummaryResource::make($this->whenLoaded('owner')),
            'closer' => UserSummaryResource::make($this->whenLoaded('closer')),
            'platform_account' => PlatformAccountSummaryResource::make($this->whenLoaded('platformAccount')),
            'parent' => OrderSummaryResource::make($this->whenLoaded('parent')),
            'items' => OrderItemResource::collection($this->whenLoaded('items')),
            'payments' => PaymentResource::collection($this->whenLoaded('payments')),
            'pending_change' => $this->pendingChange(),
            'created_at' => $this->dateTime($this->created_at),
            'updated_at' => $this->dateTime($this->updated_at),
        ];
    }
}
