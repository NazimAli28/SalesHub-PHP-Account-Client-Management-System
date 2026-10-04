<?php

namespace App\Actions\PlatformAccounts;

use App\Enums\AccountStanding;
use App\Models\PlatformAccount;

class CreatePlatformAccount
{
    /**
     * @param  array<string, mixed>  $data  Validated attributes (credentials included).
     */
    public function handle(array $data): PlatformAccount
    {
        $data['standing'] ??= AccountStanding::Active->value;

        if (($data['workstation_id'] ?? null) !== null) {
            $data['assigned_at'] = now();
        }

        if ($data['standing'] !== AccountStanding::Active->value) {
            $data['standing_changed_at'] = now();
        }

        return PlatformAccount::query()->create($data);
    }
}
