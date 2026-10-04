<?php

namespace App\Http\Resources\Summaries;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Compact user reference for embedding (owner, requester, reviewer).
 *
 * @mixin User
 */
class UserSummaryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'username' => $this->username,
        ];
    }
}
