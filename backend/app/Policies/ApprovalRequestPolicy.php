<?php

namespace App\Policies;

use App\Enums\ApprovalStatus;
use App\Models\ApprovalRequest;
use App\Models\User;
use App\Policies\Concerns\ChecksVisibility;

class ApprovalRequestPolicy
{
    use ChecksVisibility;

    public function viewAny(User $user): bool
    {
        return $this->canViewAny($user, 'approvals');
    }

    public function view(User $user, ApprovalRequest $approvalRequest): bool
    {
        return $this->viewAny($user) && $this->canSee($user, $approvalRequest);
    }

    /**
     * Maker-checker: never your own request; `review-all`, or `review-team` for a requester in your team
     * and only for lead, client, order and payment requests.
     */
    public function review(User $user, ApprovalRequest $approvalRequest): bool
    {
        if ($approvalRequest->requested_by_id === $user->id) {
            return false;
        }

        if ($user->can('approvals.review-all')) {
            return true;
        }

        if (! $user->can('approvals.review-team') || $user->team_id === null) {
            return false;
        }

        return in_array($approvalRequest->approvable_type, ApprovalRequest::TEAM_REVIEWABLE_TYPES, true)
            && $approvalRequest->requester()->where('team_id', $user->team_id)->exists();
    }

    public function cancel(User $user, ApprovalRequest $approvalRequest): bool
    {
        return $approvalRequest->requested_by_id === $user->id
            && $approvalRequest->status === ApprovalStatus::Pending;
    }
}
