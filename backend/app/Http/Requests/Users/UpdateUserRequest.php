<?php

namespace App\Http\Requests\Users;

use App\Enums\RoleName;
use App\Http\Requests\Users\Concerns\UserRules;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;

/**
 * PUT and PATCH are partial. Activation goes through the dedicated deactivate/activate endpoints.
 */
class UpdateUserRequest extends FormRequest
{
    use UserRules;

    public function authorize(): bool
    {
        return (bool) $this->user()?->can('update', $this->target());
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $rules = $this->userAttributeRules($this->route('user') instanceof User ? $this->target() : null);

        return [
            'name' => ['sometimes', 'required', ...$rules['name']],
            'username' => ['sometimes', 'required', ...$rules['username']],
            'email' => ['sometimes', 'required', ...$rules['email']],
            'password' => ['sometimes', 'required', ...$rules['password']],
            'role' => ['sometimes', 'required', ...$rules['role']],
            'team_id' => ['sometimes', ...$rules['team_id']],
            'workstation_id' => ['sometimes', ...$rules['workstation_id']],
            'is_active' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['is_active.prohibited' => 'Use the activate or deactivate endpoint to change the account status.'];
    }

    /**
     * @return list<\Closure>
     */
    public function after(): array
    {
        return [$this->workstationMatchesTeam($this->target()->team_id)];
    }

    protected function passedValidation(): void
    {
        $role = $this->role();

        if ($role !== null && ! $this->target()->hasRole($role->value)
            && ! $this->user()?->can('assignRole', [$this->target(), $role])) {
            throw new AuthorizationException('You are not allowed to assign this role.');
        }
    }

    public function target(): User
    {
        /** @var User $target */
        $target = $this->route('user');

        return $target;
    }

    public function role(): ?RoleName
    {
        $role = $this->validated('role');

        return $role === null ? null : RoleName::from((string) $role);
    }

    /**
     * @return array<string, mixed>
     */
    public function changes(): array
    {
        return Arr::except($this->validated(), ['role']);
    }
}
