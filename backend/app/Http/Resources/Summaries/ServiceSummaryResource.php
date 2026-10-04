<?php

namespace App\Http\Resources\Summaries;

use App\Http\Resources\Concerns\FormatsApiValues;
use App\Models\Service;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Compact catalog service reference.
 *
 * @mixin Service
 */
class ServiceSummaryResource extends JsonResource
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
            'slug' => $this->slug,
            'category' => $this->enum($this->category),
            'base_price' => $this->money($this->base_price_cents, $this->currency),
        ];
    }
}
