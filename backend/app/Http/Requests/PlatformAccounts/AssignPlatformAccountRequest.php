<?php

namespace App\Http\Requests\PlatformAccounts;

use App\Http\Requests\PlatformAccounts\Concerns\PlatformAccountRules;
use App\Models\PlatformAccount;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Direct write only (`platform-accounts.assign`). `workstation_id: null` unassigns the account.
 */
class AssignPlatformAccountRequest extends FormRequest
{
    use PlatformAccountRules;

    public function authorize(): bool
    {
        $account = $this->route('platformAccount');

        return $account instanceof PlatformAccount && (bool) $this->user()?->can('assign', $account);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'workstation_id' => ['present', 'nullable', 'integer', $this->activeWorkstationRule()],
        ];
    }

    public function workstationId(): ?int
    {
        $id = $this->validated('workstation_id');

        return $id === null ? null : (int) $id;
    }
}
