<?php

namespace App\Http\Requests\Teams\Concerns;

use App\Enums\RoleName;
use App\Enums\Shift;
use App\Models\Team;
use App\Models\User;
use Closure;
use Illuminate\Validation\Rule;

trait TeamRules
{
    /**
     * @return array<string, list<mixed>>
     */
    protected function teamAttributeRules(?Team $team = null): array
    {
        return [
            'name' => ['string', 'max:80', Rule::unique('teams', 'name')->ignore($team?->id)],
            'floor' => ['integer', 'min:0', 'max:255'],
            'shift' => [Rule::enum(Shift::class)],
            'team_lead_id' => [
                'nullable', 'integer',
                Rule::unique('teams', 'team_lead_id')->ignore($team?->id)->whereNull('deleted_at'),
                $this->teamLeadRule($team),
            ],
        ];
    }

    /**
     * The lead must be an active user with the team lead role who sits on this team.
     * A new team has no members yet, so for it the lead must still be unassigned (the action seats them).
     */
    private function teamLeadRule(?Team $team): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($team): void {
            $lead = User::query()->find($value);

            $valid = $lead !== null
                && $lead->is_active
                && $lead->hasRole(RoleName::TeamLead->value)
                && ($team === null ? $lead->team_id === null : $lead->team_id === $team->id);

            if (! $valid) {
                $fail($team === null
                    ? 'The team lead must be an active team lead who is not on a team yet.'
                    : 'The team lead must be an active team lead who belongs to this team.');
            }
        };
    }
}
