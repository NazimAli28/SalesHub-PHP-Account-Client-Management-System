<?php

namespace App\Http\Requests\PlatformAccounts\Concerns;

use Illuminate\Validation\Rule;

/**
 * Field rules shared by the platform account Form Requests. Prefix each list with 'required', 'sometimes' or 'nullable'.
 */
trait PlatformAccountRules
{
    /**
     * @return array<string, list<mixed>>
     */
    protected function platformAccountAttributeRules(mixed $ignore = null): array
    {
        return [
            'email' => ['string', 'email', 'max:255', Rule::unique('platform_accounts', 'email')->ignore($ignore)],
            'discord_email' => ['nullable', 'string', 'email', 'max:255', Rule::unique('platform_accounts', 'discord_email')->ignore($ignore)],
            'discord_username' => ['nullable', 'string', 'max:64'],
            'discord_created_on' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'recovery_email' => ['nullable', 'string', 'email', 'max:255'],
            'batch_date' => ['date_format:Y-m-d'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'workstation_id' => ['nullable', 'integer', $this->activeWorkstationRule()],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * Write-only credential columns (stored encrypted, never returned).
     *
     * @return array<string, list<mixed>>
     */
    protected function credentialRules(): array
    {
        return [
            'email_password' => ['string', 'min:1', 'max:255'],
            'discord_password' => ['string', 'min:1', 'max:255'],
            'recovery_phone' => ['nullable', 'string', 'max:64'],
            'phone_holder_name' => ['nullable', 'string', 'max:120'],
        ];
    }

    protected function activeWorkstationRule(): mixed
    {
        return Rule::exists('workstations', 'id')->where('is_active', true)->whereNull('deleted_at');
    }

    public function reason(): ?string
    {
        $reason = $this->validated('reason');

        return is_string($reason) ? $reason : null;
    }
}
