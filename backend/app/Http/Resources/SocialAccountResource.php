<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\FormatsApiValues;
use App\Http\Resources\Summaries\PlatformAccountSummaryResource;
use App\Models\SocialAccount;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Never outputs the password: `has_password` says whether one is stored, the reveal endpoint returns it.
 *
 * @mixin SocialAccount
 */
class SocialAccountResource extends JsonResource
{
    use FormatsApiValues;

    public const DEFAULT_WITH = ['pendingApproval.requester'];

    public const INCLUDES = ['platformAccount'];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $password = $this->resource->getAttributes()['password'] ?? null;

        return [
            'id' => $this->id,
            'platform' => $this->enum($this->platform),
            'username' => $this->username,
            'login_email' => $this->login_email,
            'created_on' => $this->date($this->created_on),
            'is_in_use' => $this->is_in_use,
            'has_password' => $password !== null && $password !== '',
            'platform_account_id' => $this->platform_account_id,
            'platform_account' => PlatformAccountSummaryResource::make($this->whenLoaded('platformAccount')),
            'pending_change' => $this->pendingChange(),
            'created_at' => $this->dateTime($this->created_at),
            'updated_at' => $this->dateTime($this->updated_at),
        ];
    }
}
