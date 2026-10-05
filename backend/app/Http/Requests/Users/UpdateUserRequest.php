<?php

namespace App\Http\Requests\Users;

use App\Actions\Auth\EnsureNotDemoMode;
use App\Enums\RoleName;
use App\Http\Requests\Concerns\ResolvesActor;
use App\Http\Requests\Users\Concerns\UserRules;
use App\Models\User;
use App\Support\DemoAccounts;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;

/**
 * PUT and PATCH are partial. Activation goes through the dedicated deactivate/activate endpoints.
 * Your own password is changed through PUT /api/auth/password (it re-checks the current one), so
 * `password` is prohibited when you edit yourself. In demo mode the seeded accounts keep their
 * password, username, email and role (403 `demo_mode`).
 */
class UpdateUserRequest extends FormRequest
{
    use ResolvesActor;
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
        $target = $this->route('user') instanceof User ? $this->target() : null;
        $rules = $this->userAttributeRules($target);
        $actor = $this->actor();
        $editingSelf = $target !== null && $actor->exists && $actor->is($target);

        return [
            'name' => ['sometimes', 'required', ...$rules['name']],
            'username' => ['sometimes', 'required', ...$rules['username']],
            'email' => ['sometimes', 'required', ...$rules['email']],
            'password' => [Rule::prohibitedIf($editingSelf), 'sometimes', 'required', ...$rules['password']],
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
        return [
            'is_active.prohibited' => 'Use the activate or deactivate endpoint to change the account status.',
            'password.prohibited' => 'Change your own password from your profile (PUT /api/auth/password).',
        ];
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
        $this->guardDemoAccount();

        $role = $this->role();

        if ($role !== null && ! $this->target()->hasRole($role->value)
            && ! $this->user()?->can('assignRole', [$this->target(), $role])) {
            throw new AuthorizationException('You are not allowed to assign this role.');
        }
    }

    /**
     * Demo mode: the seeded accounts keep everything needed to sign in to them. Unchanged values
     * (a form that sends every field) are accepted.
     */
    private function guardDemoAccount(): void
    {
        $target = $this->target();

        if (! DemoAccounts::isProtected($target)) {
            return;
        }

        $role = $this->role();

        $locking = $this->has('password')
            || ($this->has('username') && $this->validated('username') !== $target->username)
            || ($this->has('email') && $this->validated('email') !== $target->email)
            || ($role !== null && $target->getRoleNames()->values()->all() !== [$role->value]);

        if ($locking) {
            throw EnsureNotDemoMode::exception('the password, username, email and role of the demo accounts cannot be changed');
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
