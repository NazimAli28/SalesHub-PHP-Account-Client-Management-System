<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\FormatsApiValues;
use App\Http\Resources\Summaries\ClientSummaryResource;
use App\Http\Resources\Summaries\PlatformAccountSummaryResource;
use App\Http\Resources\Summaries\ServiceSummaryResource;
use App\Http\Resources\Summaries\UserSummaryResource;
use App\Models\Lead;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Lead
 */
class LeadResource extends JsonResource
{
    use FormatsApiValues;

    /**
     * Relations the controller always eager-loads, so `pending_change` is present.
     */
    public const DEFAULT_WITH = ['pendingApproval.requester'];

    /**
     * Whitelisted `?include=` values (relation names).
     */
    public const INCLUDES = ['client', 'owner', 'closer', 'platformAccount', 'services'];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'stage' => $this->enum($this->stage),
            'stage_changed_at' => $this->dateTime($this->stage_changed_at),
            'contacted_on' => $this->date($this->contacted_on),
            'estimated_value' => $this->money($this->estimated_value_cents, $this->currency),
            'last_message' => $this->last_message,
            'next_follow_up_on' => $this->date($this->next_follow_up_on),
            'lost_reason' => $this->enum($this->lost_reason),
            'lost_note' => $this->lost_note,
            'client_id' => $this->client_id,
            'owner_id' => $this->owner_id,
            'closer_id' => $this->closer_id,
            'platform_account_id' => $this->platform_account_id,
            'order_id' => $this->order_id,
            'client' => ClientSummaryResource::make($this->whenLoaded('client')),
            'owner' => UserSummaryResource::make($this->whenLoaded('owner')),
            'closer' => UserSummaryResource::make($this->whenLoaded('closer')),
            'platform_account' => PlatformAccountSummaryResource::make($this->whenLoaded('platformAccount')),
            'services' => ServiceSummaryResource::collection($this->whenLoaded('services')),
            'pending_change' => $this->pendingChange(),
            'created_at' => $this->dateTime($this->created_at),
            'updated_at' => $this->dateTime($this->updated_at),
        ];
    }
}
