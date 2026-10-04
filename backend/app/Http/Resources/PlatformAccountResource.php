<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\FormatsApiValues;
use App\Http\Resources\Summaries\WorkstationSummaryResource;
use App\Models\PlatformAccount;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Never outputs credential values: `has_*` booleans say whether a secret is stored, the
 * `POST /platform-accounts/{id}/reveal` endpoint returns the values.
 *
 * @mixin PlatformAccount
 */
class PlatformAccountResource extends JsonResource
{
    use FormatsApiValues;

    /**
     * Relations the controller always eager-loads (`social_accounts_count` is added with withCount).
     */
    public const DEFAULT_WITH = ['pendingApproval.requester', 'workstation.team'];

    /**
     * Whitelisted `?include=` values (relation names).
     */
    public const INCLUDES = ['workstation', 'workstation.team', 'socialAccounts'];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'email' => $this->email,
            'discord_email' => $this->discord_email,
            'discord_username' => $this->discord_username,
            'discord_created_on' => $this->date($this->discord_created_on),
            'recovery_email' => $this->recovery_email,
            'batch_date' => $this->date($this->batch_date),
            'standing' => $this->enum($this->standing),
            'standing_changed_at' => $this->dateTime($this->standing_changed_at),
            'notes' => $this->notes,
            'has_email_password' => $this->hasSecret('email_password'),
            'has_discord_password' => $this->hasSecret('discord_password'),
            'has_recovery_phone' => $this->hasSecret('recovery_phone'),
            'has_phone_holder_name' => $this->hasSecret('phone_holder_name'),
            'workstation_id' => $this->workstation_id,
            'assigned_at' => $this->dateTime($this->assigned_at),
            'workstation' => WorkstationSummaryResource::make($this->whenLoaded('workstation')),
            'social_accounts_count' => $this->whenCounted('socialAccounts'),
            'social_accounts' => SocialAccountResource::collection($this->whenLoaded('socialAccounts')),
            'pending_change' => $this->pendingChange(),
            'created_at' => $this->dateTime($this->created_at),
            'updated_at' => $this->dateTime($this->updated_at),
        ];
    }

    private function hasSecret(string $column): bool
    {
        $raw = $this->resource->getAttributes()[$column] ?? null;

        return $raw !== null && $raw !== '';
    }
}
