<?php

namespace App\Approvals\Appliers;

use App\Approvals\Contracts\ApprovalApplier;
use App\Models\ApprovalRequest;
use Illuminate\Database\Eloquent\Model;

/**
 * "Request new platform accounts" (action `request_accounts`): approving only records the decision.
 * Nothing is created automatically; support then assigns accounts to the workstation by hand.
 */
class RequestAccountsApplier implements ApprovalApplier
{
    public function apply(ApprovalRequest $approval, ?Model $record): ?Model
    {
        return null;
    }
}
