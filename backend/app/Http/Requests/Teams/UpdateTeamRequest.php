<?php

namespace App\Http\Requests\Teams;

use App\Http\Requests\Teams\Concerns\TeamRules;
use App\Models\Team;
use Illuminate\Foundation\Http\FormRequest;

class UpdateTeamRequest extends FormRequest
{
    use TeamRules;

    public function authorize(): bool
    {
        return (bool) $this->user()?->can('update', $this->team());
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $rules = $this->teamAttributeRules($this->team());

        return [
            'name' => ['sometimes', 'required', ...$rules['name']],
            'floor' => ['sometimes', 'required', ...$rules['floor']],
            'shift' => ['sometimes', 'required', ...$rules['shift']],
            'team_lead_id' => ['sometimes', ...$rules['team_lead_id']],
        ];
    }

    public function team(): Team
    {
        /** @var Team $team */
        $team = $this->route('team');

        return $team;
    }

    /**
     * @return array<string, mixed>
     */
    public function changes(): array
    {
        return $this->validated();
    }
}
