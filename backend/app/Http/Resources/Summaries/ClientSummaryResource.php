<?php

namespace App\Http\Resources\Summaries;

use App\Http\Resources\Concerns\FormatsApiValues;
use App\Models\Client;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Compact client reference for embedding in leads and orders.
 *
 * @mixin Client
 */
class ClientSummaryResource extends JsonResource
{
    use FormatsApiValues;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'discord_username' => $this->discord_username,
            'name' => $this->name,
            'status' => $this->enum($this->status),
        ];
    }
}
