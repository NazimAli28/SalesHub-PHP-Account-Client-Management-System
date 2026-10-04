<?php

namespace App\Http\Requests\PlatformAccounts;

use App\Http\Requests\Concerns\AuthorizesChangeOrRequest;
use App\Http\Requests\PlatformAccounts\Concerns\PlatformAccountRules;
use App\Models\PlatformAccount;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;

/**
 * PUT and PATCH are both partial updates, used for the direct write and for the approval payload alike.
 * Credentials are accepted only from users who may write directly (support/admin); a queued change never carries them.
 * Workstation and standing have their own endpoints (assign, standing).
 */
class UpdatePlatformAccountRequest extends FormRequest
{
    use AuthorizesChangeOrRequest, PlatformAccountRules;

    public function authorize(): bool
    {
        return $this->canChangeOrRequest('update', $this->route('platformAccount'));
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $account = $this->route('platformAccount');
        $account = $account instanceof PlatformAccount ? $account : null;
        $attributes = $this->platformAccountAttributeRules($account);
        $credentials = $this->credentialRules();
        $direct = $account !== null && (bool) $this->user()?->can('update', $account);

        $rules = [
            'email' => ['sometimes', ...$attributes['email']],
            'discord_email' => ['sometimes', ...$attributes['discord_email']],
            'discord_username' => ['sometimes', ...$attributes['discord_username']],
            'discord_created_on' => ['sometimes', ...$attributes['discord_created_on']],
            'recovery_email' => ['sometimes', ...$attributes['recovery_email']],
            'batch_date' => ['sometimes', ...$attributes['batch_date']],
            'notes' => ['sometimes', ...$attributes['notes']],
            'workstation_id' => ['prohibited'],
            'standing' => ['prohibited'],
            'reason' => $attributes['reason'],
        ];

        foreach (['email_password', 'discord_password', 'recovery_phone', 'phone_holder_name'] as $field) {
            $rules[$field] = $direct ? ['sometimes', ...$credentials[$field]] : ['prohibited'];
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'workstation_id.prohibited' => 'Use PATCH /api/platform-accounts/{id}/assign to change the workstation.',
            'standing.prohibited' => 'Use PATCH /api/platform-accounts/{id}/standing to change the standing.',
            'email_password.prohibited' => 'Credentials can only be changed by support or admin.',
            'discord_password.prohibited' => 'Credentials can only be changed by support or admin.',
            'recovery_phone.prohibited' => 'Credentials can only be changed by support or admin.',
            'phone_holder_name.prohibited' => 'Credentials can only be changed by support or admin.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function changes(): array
    {
        return Arr::except($this->validated(), ['reason']);
    }
}
