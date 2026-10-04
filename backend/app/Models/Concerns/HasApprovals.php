<?php

namespace App\Models\Concerns;

use App\Enums\ApprovalStatus;
use App\Models\ApprovalRequest;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

trait HasApprovals
{
    /**
     * @return MorphMany<ApprovalRequest, $this>
     */
    public function approvalRequests(): MorphMany
    {
        return $this->morphMany(ApprovalRequest::class, 'approvable');
    }

    /**
     * The latest pending approval request for this record, if any.
     *
     * @return MorphOne<ApprovalRequest, $this>
     */
    public function pendingApproval(): MorphOne
    {
        return $this->morphOne(ApprovalRequest::class, 'approvable')
            ->ofMany(['id' => 'max'], fn ($query) => $query->where('status', ApprovalStatus::Pending->value));
    }
}
