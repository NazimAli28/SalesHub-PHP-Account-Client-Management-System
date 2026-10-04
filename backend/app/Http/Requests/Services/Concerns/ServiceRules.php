<?php

namespace App\Http\Requests\Services\Concerns;

use App\Enums\ServiceCategory;
use App\Models\Service;
use Illuminate\Validation\Rule;

trait ServiceRules
{
    /**
     * @return array<string, list<mixed>>
     */
    protected function serviceAttributeRules(?Service $service = null): array
    {
        return [
            'name' => ['string', 'max:80'],
            'slug' => ['string', 'max:80', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('services', 'slug')->ignore($service?->id)],
            'category' => [Rule::enum(ServiceCategory::class)],
            'description' => ['nullable', 'string', 'max:2000'],
            'base_price_cents' => ['integer', 'min:0', 'max:100000000'],
            'currency' => ['string', 'regex:/^[A-Z]{3}$/'],
            'is_active' => ['boolean'],
        ];
    }
}
