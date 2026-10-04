<?php

namespace App\Http\Requests\Services;

use App\Http\Requests\Services\Concerns\ServiceRules;
use App\Models\Service;
use Illuminate\Foundation\Http\FormRequest;

/**
 * `slug` is optional and defaults to the slugified name.
 */
class StoreServiceRequest extends FormRequest
{
    use ServiceRules;

    public function authorize(): bool
    {
        return (bool) $this->user()?->can('create', Service::class);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $rules = $this->serviceAttributeRules();

        return [
            'name' => ['required', ...$rules['name']],
            'slug' => ['sometimes', ...$rules['slug']],
            'category' => ['required', ...$rules['category']],
            'description' => $rules['description'],
            'base_price_cents' => ['required', ...$rules['base_price_cents']],
            'currency' => ['sometimes', ...$rules['currency']],
            'is_active' => ['sometimes', ...$rules['is_active']],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function serviceAttributes(): array
    {
        return $this->validated();
    }
}
