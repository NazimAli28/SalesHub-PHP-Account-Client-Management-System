<?php

namespace App\Approvals\Appliers;

use App\Models\SocialAccount;

/**
 * Plain attribute updates and soft deletes. The password column never appears in payloads.
 */
class SocialAccountApplier extends AttributeApplier
{
    protected function model(): string
    {
        return SocialAccount::class;
    }
}
