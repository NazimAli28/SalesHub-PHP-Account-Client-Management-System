<?php

namespace App\Actions\Approvals;

use App\Enums\ApprovalStatus;
use App\Exceptions\ApprovalConflictException;
use App\Models\ApprovalRequest;
use App\Models\User;
use App\Notifications\ApprovalDecided;
use App\Support\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Rejects a pending request; no data is written. The caller has authorized `review` and validated the comment.
 */
class RejectRequest
{
    /**
     * @throws ApprovalConflictException
     * @throws AuthorizationException
     */
    public function handle(ApprovalRequest $approval, User $reviewer, string $comment): ApprovalRequest
    {
        /** @var ApprovalRequest $decided */
        $decided = DB::transaction(function () use ($approval, $reviewer, $comment): ApprovalRequest {
            $locked = ApprovalRequest::query()->whereKey($approval->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->isPending()) {
                throw ApprovalConflictException::alreadyDecided();
            }

            if ($locked->requested_by_id === $reviewer->id) {
                throw new AuthorizationException;
            }

            $locked->transitionFrom(ApprovalStatus::Pending, [
                'status' => ApprovalStatus::Rejected,
                'reviewed_by_id' => $reviewer->id,
                'reviewed_at' => now(),
                'review_comment' => $comment,
                'pending_key' => null,
            ]);

            return $locked;
        });

        $decided->load(['requester', 'reviewer']);

        AuditLogger::approval('rejected', $decided, $reviewer);
        $decided->requester?->notify(new ApprovalDecided($decided));

        return $decided;
    }
}
