<?php

namespace App\Http\Requests\Workstations\Concerns;

use App\Models\Workstation;
use Illuminate\Validation\Rule;

trait WorkstationRules
{
    /**
     * @return array<string, list<mixed>>
     */
    protected function workstationAttributeRules(?Workstation $workstation = null): array
    {
        return [
            'code' => ['string', 'max:20', Rule::unique('workstations', 'code')->ignore($workstation?->id)],
            'team_id' => ['integer', Rule::exists('teams', 'id')->whereNull('deleted_at')],
            'label' => ['nullable', 'string', 'max:80'],
            'is_active' => ['boolean'],
        ];
    }
}
