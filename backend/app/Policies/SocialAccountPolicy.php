<?php

namespace App\Policies;

use App\Models\SocialAccount;
use App\Models\User;

class SocialAccountPolicy extends ScopedResourcePolicy
{
    protected function resource(): string
    {
        return 'social-accounts';
    }

    public function revealCredentials(User $user, SocialAccount $socialAccount): bool
    {
        return $this->canOn($user, 'social-accounts.reveal-credentials', $socialAccount);
    }
}
