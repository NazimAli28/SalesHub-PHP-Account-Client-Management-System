<?php

namespace App\Actions\SocialAccounts;

use App\Models\SocialAccount;

class CreateSocialAccount
{
    /**
     * @param  array<string, mixed>  $data  Validated attributes (password included).
     */
    public function handle(array $data): SocialAccount
    {
        $data['is_in_use'] ??= false;

        return SocialAccount::query()->create($data);
    }
}
