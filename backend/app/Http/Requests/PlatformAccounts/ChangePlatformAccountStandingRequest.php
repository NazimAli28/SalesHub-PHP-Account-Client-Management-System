<?php

namespace App\Http\Requests\PlatformAccounts;

use App\Enums\AccountStanding;
use App\Models\PlatformAccount;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Support/admin change the standing directly; team leads and sales executives request it.
 */
class ChangePlatformAccountStandingRequest extends FormRequest
{
    public function authorize(): bool
    {
        $account = $this->route('platformAccount');
        $user = $this->user();

        return $user !== null && $account instanceof PlatformAccount
            && ($user->can('changeStanding', $account) || $user->can('requestChange', $account));
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'standing' => ['required', Rule::enum(AccountStanding::class)],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function standing(): string
    {
        return (string) $this->validated('standing');
    }

    public function reason(): ?string
    {
        $reason = $this->validated('reason');

        return is_string($reason) ? $reason : null;
    }
}
