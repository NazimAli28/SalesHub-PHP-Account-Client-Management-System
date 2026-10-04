<?php

namespace App\Approvals\Appliers;

use App\Models\PlatformAccount;

/**
 * Plain attribute updates and soft deletes. Credential columns never appear in payloads (they are
 * support/admin direct writes). The Platform Accounts module should override update() to call its
 * action when a change needs side effects (standing_changed_at, assigned_at).
 */
class PlatformAccountApplier extends AttributeApplier
{
    protected function model(): string
    {
        return PlatformAccount::class;
    }
}
