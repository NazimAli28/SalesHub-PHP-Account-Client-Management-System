<?php

namespace App\Approvals\Contracts;

use App\Models\ApprovalRequest;
use Illuminate\Database\Eloquent\Model;

/**
 * Applies an approved request to the database. One applier per morph alias, found by convention:
 * alias `platform_account` -> App\Approvals\Appliers\PlatformAccountApplier (see ApplierRegistry).
 *
 * Appliers run inside the approval transaction with the target row locked, and must call the same
 * Action classes as the direct-write controller path so both paths behave identically.
 */
interface ApprovalApplier
{
    /**
     * @param  Model|null  $record  The locked target record (update/delete); null for create and request_accounts.
     * @return Model|null The record after the change (used for the `after` snapshot), or null when nothing was written.
     */
    public function apply(ApprovalRequest $approval, ?Model $record): ?Model;
}
