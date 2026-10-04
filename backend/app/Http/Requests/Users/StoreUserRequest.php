<?php

namespace App\Http\Requests\Users;

use App\Enums\RoleName;
use App\Http\Requests\Users\Concerns\UserRules;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;

/**
 * Creates a user with exactly one role. Support may only create team leads and sales executives.
 */
class StoreUserRequest extends FormRequest
{
    use UserRules;

    public function authorize(): bool
    {
        return (bool) $this->user()?->can('create', User::class);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $rules = $this->userAttributeRules();

        return [
            'name' => ['required', ...$rules['name']],
            'username' => ['required', ...$rules['username']],
            'email' => ['required', ...$rules['email']],
            'password' => ['required', ...$rules['password']],
            'role' => ['required', ...$rules['role']],
            'team_id' => $rules['team_id'],
            'workstation_id' => $rules['workstation_id'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return list<\Closure>
     */
    public function after(): array
    {
        return [$this->workstationMatchesTeam(null)];
    }

    protected function passedValidation(): void
    {
        if (! $this->user()?->can('assignRole', [new User, $this->role()])) {
            throw new AuthorizationException('You are not allowed to assign this role.');
        }
    }

    public function role(): RoleName
    {
        return RoleName::from((string) $this->validated('role'));
    }

    /**
     * @return array<string, mixed>
     */
    public function userAttributes(): array
    {
        return Arr::except($this->validated(), ['role']);
    }
}
