<?php

namespace App\Http\Requests\Teams;

use App\Http\Requests\Teams\Concerns\TeamRules;
use App\Models\Team;
use Illuminate\Foundation\Http\FormRequest;

class StoreTeamRequest extends FormRequest
{
    use TeamRules;

    public function authorize(): bool
    {
        return (bool) $this->user()?->can('create', Team::class);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $rules = $this->teamAttributeRules();

        return [
            'name' => ['required', ...$rules['name']],
            'floor' => ['required', ...$rules['floor']],
            'shift' => ['required', ...$rules['shift']],
            'team_lead_id' => $rules['team_lead_id'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function teamAttributes(): array
    {
        return $this->validated();
    }
}
