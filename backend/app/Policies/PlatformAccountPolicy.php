<?php

namespace App\Policies;

use App\Models\PlatformAccount;
use App\Models\User;

class PlatformAccountPolicy extends ScopedResourcePolicy
{
    protected function resource(): string
    {
        return 'platform-accounts';
    }

    public function revealCredentials(User $user, PlatformAccount $platformAccount): bool
    {
        return $this->canOn($user, 'platform-accounts.reveal-credentials', $platformAccount);
    }

    public function assign(User $user, PlatformAccount $platformAccount): bool
    {
        return $this->canOn($user, 'platform-accounts.assign', $platformAccount);
    }

    public function changeStanding(User $user, PlatformAccount $platformAccount): bool
    {
        return $this->canOn($user, 'platform-accounts.change-standing', $platformAccount);
    }

    public function requestNew(User $user): bool
    {
        return $user->can('platform-accounts.request-new');
    }
}
