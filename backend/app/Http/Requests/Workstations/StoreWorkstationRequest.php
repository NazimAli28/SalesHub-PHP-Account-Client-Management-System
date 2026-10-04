<?php

namespace App\Http\Requests\Workstations;

use App\Http\Requests\Workstations\Concerns\WorkstationRules;
use App\Models\Workstation;
use Illuminate\Foundation\Http\FormRequest;

class StoreWorkstationRequest extends FormRequest
{
    use WorkstationRules;

    public function authorize(): bool
    {
        return (bool) $this->user()?->can('create', Workstation::class);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $rules = $this->workstationAttributeRules();

        return [
            'code' => ['required', ...$rules['code']],
            'team_id' => ['required', ...$rules['team_id']],
            'label' => $rules['label'],
            'is_active' => ['sometimes', ...$rules['is_active']],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function workstationAttributes(): array
    {
        return $this->validated();
    }
}
