<?php

namespace App\Http\Resources\Summaries;

use App\Http\Resources\Concerns\FormatsApiValues;
use App\Models\Team;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Compact team reference for embedding.
 *
 * @mixin Team
 */
class TeamSummaryResource extends JsonResource
{
    use FormatsApiValues;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'floor' => $this->floor,
            'shift' => $this->enum($this->shift),
        ];
    }
}
