<?php

namespace App\Http\Requests\Approvals;

use App\Models\PlatformAccount;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * "Request new platform accounts" for a workstation in the user's scope (defaults to their own seat).
 */
class RequestAccountsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('requestNew', PlatformAccount::class);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        /** @var User $user */
        $user = $this->user();

        return [
            'workstation_id' => [
                Rule::requiredIf($user->workstation_id === null),
                'nullable',
                'integer',
                Rule::exists('workstations', 'id')
                    ->where('is_active', true)
                    ->whereNull('deleted_at')
                    ->where(fn (Builder $q) => $this->limitToScope($q, $user)),
            ],
            'quantity' => ['required', 'integer', 'min:1', 'max:10'],
            'note' => ['nullable', 'string', 'max:500'],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function workstationId(): int
    {
        /** @var User $user */
        $user = $this->user();

        return (int) ($this->validated('workstation_id') ?? $user->workstation_id);
    }

    /**
     * Same tiers as platform account visibility: all, own team's workstations, or own workstation.
     */
    private function limitToScope(Builder $query, User $user): void
    {
        if ($user->can('platform-accounts.view-all')) {
            return;
        }

        if ($user->can('platform-accounts.view-team') && $user->team_id !== null) {
            $query->where('team_id', $user->team_id);

            return;
        }

        $query->where('id', $user->workstation_id ?? 0);
    }
}
