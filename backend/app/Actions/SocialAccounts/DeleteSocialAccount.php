<?php

namespace App\Actions\SocialAccounts;

use App\Models\SocialAccount;

class DeleteSocialAccount
{
    /**
     * Soft delete.
     */
    public function handle(SocialAccount $account): void
    {
        $account->delete();
    }
}
