<?php

namespace App\Actions\PlatformAccounts;

use App\Models\PlatformAccount;

/**
 * The single write path for platform account changes (controllers and PlatformAccountApplier).
 */
class UpdatePlatformAccount
{
    /**
     * Adds the side effects of a change: `standing_changed_at` when the standing really changes,
     * `assigned_at` when the workstation really changes (null when unassigned). Pure.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function prepare(PlatformAccount $account, array $data): array
    {
        if (isset($data['standing']) && $data['standing'] !== $account->standing->value) {
            $data['standing_changed_at'] = now();
        }

        if (array_key_exists('workstation_id', $data) && $data['workstation_id'] !== $account->workstation_id) {
            $data['assigned_at'] = $data['workstation_id'] === null ? null : now();
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $changes  Output of prepare().
     */
    public function handle(PlatformAccount $account, array $changes): PlatformAccount
    {
        $account->fill($changes)->save();

        return $account;
    }
}
