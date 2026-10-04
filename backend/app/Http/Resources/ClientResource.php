<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\FormatsApiValues;
use App\Http\Resources\Summaries\UserSummaryResource;
use App\Models\Client;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * `lifetime_value` (paid payments, USD display) appears when the query used `withLifetimeValue()`.
 *
 * @mixin Client
 */
class ClientResource extends JsonResource
{
    use FormatsApiValues;

    /**
     * Relations the controller always eager-loads, so `pending_change` is present.
     */
    public const DEFAULT_WITH = ['pendingApproval.requester'];

    /**
     * Whitelisted `?include=` values (relation names).
     */
    public const INCLUDES = ['owner', 'leads', 'orders'];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'discord_username' => $this->discord_username,
            'name' => $this->name,
            'email' => $this->email,
            'payment_name' => $this->payment_name,
            'country' => $this->country,
            'status' => $this->enum($this->status),
            'nurturing_rating' => $this->nurturing_rating,
            'next_upsell_plan' => $this->next_upsell_plan,
            'expected_upsell_on' => $this->date($this->expected_upsell_on),
            'lost_note' => $this->lost_note,
            'notes' => $this->notes,
            'owner_id' => $this->owner_id,
            'lifetime_value' => $this->when(
                array_key_exists('lifetime_value_cents', $this->resource->getAttributes()),
                fn () => $this->money((int) $this->resource->getAttribute('lifetime_value_cents'), 'USD'),
            ),
            'owner' => UserSummaryResource::make($this->whenLoaded('owner')),
            'leads' => LeadResource::collection($this->whenLoaded('leads')),
            'orders' => OrderResource::collection($this->whenLoaded('orders')),
            'pending_change' => $this->pendingChange(),
            'created_at' => $this->dateTime($this->created_at),
            'updated_at' => $this->dateTime($this->updated_at),
        ];
    }
}
