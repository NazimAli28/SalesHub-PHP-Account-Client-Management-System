<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\FormatsApiValues;
use App\Http\Resources\Summaries\UserSummaryResource;
use App\Models\Team;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Team
 */
class TeamResource extends JsonResource
{
    use FormatsApiValues;

    public const DEFAULT_WITH = ['teamLead'];

    public const INCLUDES = ['members', 'workstations'];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'display_name' => $this->display_name,
            'floor' => $this->floor,
            'shift' => $this->enum($this->shift),
            'team_lead_id' => $this->team_lead_id,
            'team_lead' => UserSummaryResource::make($this->whenLoaded('teamLead')),
            'members_count' => $this->whenCounted('members'),
            'workstations_count' => $this->whenCounted('workstations'),
            'members' => UserSummaryResource::collection($this->whenLoaded('members')),
            'workstations' => WorkstationResource::collection($this->whenLoaded('workstations')),
            'created_at' => $this->dateTime($this->created_at),
            'updated_at' => $this->dateTime($this->updated_at),
        ];
    }
}
