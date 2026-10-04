<?php

namespace App\Http\Requests\Users\Concerns;

use App\Enums\RoleName;
use App\Models\User;
use App\Models\Workstation;
use Closure;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Validator;

trait UserRules
{
    /**
     * Rules shared by store and update; `$ignore` is the user being updated.
     *
     * @return array<string, list<mixed>>
     */
    protected function userAttributeRules(?User $ignore = null): array
    {
        return [
            'name' => ['string', 'max:120'],
            'username' => [
                'string', 'min:3', 'max:50', 'regex:/^[a-z0-9._-]+$/',
                Rule::unique('users', 'username')->ignore($ignore?->id),
            ],
            'email' => ['string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($ignore?->id)],
            'password' => ['string', Password::defaults()],
            'role' => [Rule::enum(RoleName::class)],
            'team_id' => ['nullable', 'integer', Rule::exists('teams', 'id')->whereNull('deleted_at')],
            'workstation_id' => ['nullable', 'integer', Rule::exists('workstations', 'id')->whereNull('deleted_at')],
        ];
    }

    /**
     * Usernames and emails are stored and matched in lowercase (login lowercases the identifier).
     */
    protected function prepareForValidation(): void
    {
        $lowered = [];
        foreach (['username', 'email'] as $field) {
            if (is_string($this->input($field))) {
                $lowered[$field] = mb_strtolower(trim($this->input($field)));
            }
        }

        $this->merge($lowered);
    }

    /**
     * The workstation must belong to the (resulting) team of the user.
     *
     * @return Closure(Validator): void
     */
    protected function workstationMatchesTeam(?int $currentTeamId): Closure
    {
        return function (Validator $validator) use ($currentTeamId): void {
            $workstationId = $this->input('workstation_id');

            if ($workstationId === null || $validator->errors()->hasAny(['workstation_id', 'team_id'])) {
                return;
            }

            $teamId = $this->has('team_id') ? $this->input('team_id') : $currentTeamId;

            $matches = $teamId !== null && Workstation::query()
                ->whereKey($workstationId)
                ->where('team_id', $teamId)
                ->exists();

            if (! $matches) {
                $validator->errors()->add('workstation_id', 'The workstation must belong to the selected team.');
            }
        };
    }
}
