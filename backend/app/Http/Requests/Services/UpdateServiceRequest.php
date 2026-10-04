<?php

namespace App\Http\Requests\Services;

use App\Http\Requests\Services\Concerns\ServiceRules;
use App\Models\Service;
use Illuminate\Foundation\Http\FormRequest;

class UpdateServiceRequest extends FormRequest
{
    use ServiceRules;

    public function authorize(): bool
    {
        return (bool) $this->user()?->can('update', $this->service());
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $rules = $this->serviceAttributeRules($this->route('service') instanceof Service ? $this->service() : null);

        return [
            'name' => ['sometimes', 'required', ...$rules['name']],
            'slug' => ['sometimes', 'required', ...$rules['slug']],
            'category' => ['sometimes', 'required', ...$rules['category']],
            'description' => ['sometimes', ...$rules['description']],
            'base_price_cents' => ['sometimes', 'required', ...$rules['base_price_cents']],
            'currency' => ['sometimes', 'required', ...$rules['currency']],
            'is_active' => ['sometimes', ...$rules['is_active']],
        ];
    }

    public function service(): Service
    {
        /** @var Service $service */
        $service = $this->route('service');

        return $service;
    }

    /**
     * @return array<string, mixed>
     */
    public function changes(): array
    {
        return $this->validated();
    }
}
