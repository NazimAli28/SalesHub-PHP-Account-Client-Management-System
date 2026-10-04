<?php

namespace App\Actions\Approvals;

use App\Enums\ApprovalStatus;
use App\Exceptions\ApprovalConflictException;
use App\Models\ApprovalRequest;
use App\Models\User;
use App\Support\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * The requester withdraws their own pending request. The caller has authorized `cancel`.
 */
class CancelRequest
{
    /**
     * @throws ApprovalConflictException
     */
    public function handle(ApprovalRequest $approval, User $requester): ApprovalRequest
    {
        /** @var ApprovalRequest $cancelled */
        $cancelled = DB::transaction(function () use ($approval): ApprovalRequest {
            $locked = ApprovalRequest::query()->whereKey($approval->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->isPending()) {
                throw ApprovalConflictException::alreadyDecided();
            }

            $locked->transitionFrom(ApprovalStatus::Pending, [
                'status' => ApprovalStatus::Cancelled,
                'pending_key' => null,
            ]);

            return $locked;
        });

        $cancelled->load(['requester', 'reviewer']);

        AuditLogger::approval('cancelled', $cancelled, $requester);

        return $cancelled;
    }
}
