<?php

namespace App\Http\Resources\Summaries;

use App\Http\Resources\Concerns\FormatsApiValues;
use App\Models\PlatformAccount;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Compact platform account reference. Never contains credential columns.
 *
 * @mixin PlatformAccount
 */
class PlatformAccountSummaryResource extends JsonResource
{
    use FormatsApiValues;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'email' => $this->email,
            'discord_username' => $this->discord_username,
            'standing' => $this->enum($this->standing),
        ];
    }
}
