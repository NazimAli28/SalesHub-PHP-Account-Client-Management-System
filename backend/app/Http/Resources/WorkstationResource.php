<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\FormatsApiValues;
use App\Http\Resources\Summaries\TeamSummaryResource;
use App\Http\Resources\Summaries\UserSummaryResource;
use App\Models\Workstation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Workstation
 */
class WorkstationResource extends JsonResource
{
    use FormatsApiValues;

    public const DEFAULT_WITH = ['team'];

    public const INCLUDES = ['users'];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'label' => $this->label,
            'is_active' => $this->is_active,
            'team_id' => $this->team_id,
            'team' => TeamSummaryResource::make($this->whenLoaded('team')),
            'users_count' => $this->whenCounted('users'),
            'platform_accounts_count' => $this->whenCounted('platformAccounts'),
            'users' => UserSummaryResource::collection($this->whenLoaded('users')),
            'created_at' => $this->dateTime($this->created_at),
            'updated_at' => $this->dateTime($this->updated_at),
        ];
    }
}
