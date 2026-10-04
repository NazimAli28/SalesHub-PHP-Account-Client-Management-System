<?php

namespace App\Actions\PlatformAccounts;

use App\Models\PlatformAccount;

class DeletePlatformAccount
{
    /**
     * Soft delete.
     */
    public function handle(PlatformAccount $account): void
    {
        $account->delete();
    }
}
