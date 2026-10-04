<?php

namespace App\Actions\SocialAccounts;

use App\Models\SocialAccount;

class UpdateSocialAccount
{
    /**
     * @param  array<string, mixed>  $changes  Validated attribute changes.
     */
    public function handle(SocialAccount $account, array $changes): SocialAccount
    {
        $account->fill($changes)->save();

        return $account;
    }
}
