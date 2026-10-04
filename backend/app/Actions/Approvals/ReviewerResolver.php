<?php

namespace App\Actions\Approvals;

use App\Models\ApprovalRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * Who may decide a request (mirrors ApprovalRequestPolicy::review): active users with `approvals.review-all`,
 * plus team leads with `approvals.review-team` on the requester's team for lead/client/order/payment requests.
 */
final class ReviewerResolver
{
    /**
     * @return Collection<int, User>
     */
    public static function for(ApprovalRequest $approval): Collection
    {
        $requester = $approval->requester;

        $reviewers = User::permission('approvals.review-all')
            ->where('is_active', true)
            ->whereKeyNot($approval->requested_by_id)
            ->get();

        if ($requester?->team_id !== null && in_array($approval->approvable_type, ApprovalRequest::TEAM_REVIEWABLE_TYPES, true)) {
            $teamLeads = User::permission('approvals.review-team')
                ->where('is_active', true)
                ->where('team_id', $requester->team_id)
                ->whereKeyNot($approval->requested_by_id)
                ->get();

            $reviewers = $reviewers->merge($teamLeads)->unique('id')->values();
        }

        return $reviewers;
    }
}
