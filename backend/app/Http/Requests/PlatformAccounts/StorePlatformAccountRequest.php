<?php

namespace App\Http\Requests\PlatformAccounts;

use App\Enums\AccountStanding;
use App\Http\Requests\PlatformAccounts\Concerns\PlatformAccountRules;
use App\Models\PlatformAccount;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;

/**
 * Creating a platform account is a direct, support/admin-only write. Credentials are accepted here (write-only).
 */
class StorePlatformAccountRequest extends FormRequest
{
    use PlatformAccountRules;

    public function authorize(): bool
    {
        return (bool) $this->user()?->can('create', PlatformAccount::class);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $attributes = $this->platformAccountAttributeRules();
        $credentials = $this->credentialRules();

        return [
            'email' => ['required', ...$attributes['email']],
            'email_password' => ['required', ...$credentials['email_password']],
            'discord_email' => $attributes['discord_email'],
            'discord_username' => $attributes['discord_username'],
            'discord_password' => ['required', ...$credentials['discord_password']],
            'discord_created_on' => $attributes['discord_created_on'],
            'recovery_email' => $attributes['recovery_email'],
            'recovery_phone' => $credentials['recovery_phone'],
            'phone_holder_name' => $credentials['phone_holder_name'],
            'batch_date' => ['required', ...$attributes['batch_date']],
            'workstation_id' => $attributes['workstation_id'],
            'standing' => ['sometimes', Rule::enum(AccountStanding::class)],
            'notes' => $attributes['notes'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function accountAttributes(): array
    {
        return Arr::except($this->validated(), ['reason']);
    }
}
