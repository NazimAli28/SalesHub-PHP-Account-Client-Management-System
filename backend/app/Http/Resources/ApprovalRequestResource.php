<?php

namespace App\Http\Resources;

use App\Approvals\ApprovalDiff;
use App\Http\Resources\Concerns\FormatsApiValues;
use App\Http\Resources\Summaries\UserSummaryResource;
use App\Models\ApprovalRequest;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ApprovalRequest
 */
class ApprovalRequestResource extends JsonResource
{
    use FormatsApiValues;

    private bool $withAbilities = false;

    /**
     * Adds `can: {review, cancel}` for the current user (detail view; costs one query per request).
     */
    public function withAbilities(): static
    {
        $this->withAbilities = true;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var User|null $user */
        $user = $request->user();

        return [
            'id' => $this->id,
            'action' => $this->enum($this->action),
            'status' => $this->enum($this->status),
            'approvable' => $this->approvable_type === null ? null : [
                'type' => $this->approvable_type,
                'id' => $this->approvable_id,
            ],
            'fields' => $this->changedFields(),
            'payload' => $this->payload,
            'before' => $this->before,
            'after' => $this->after,
            'diff' => ApprovalDiff::for($this->resource),
            'reason' => $this->reason,
            'requester' => UserSummaryResource::make($this->whenLoaded('requester')),
            'reviewer' => UserSummaryResource::make($this->whenLoaded('reviewer')),
            'reviewed_at' => $this->dateTime($this->reviewed_at),
            'review_comment' => $this->review_comment,
            'applied_at' => $this->dateTime($this->applied_at),
            'failure_message' => $this->failure_message,
            'created_at' => $this->dateTime($this->created_at),
            'updated_at' => $this->dateTime($this->updated_at),
            'can' => $this->when($this->withAbilities && $user !== null, fn () => [
                'review' => $this->isPending() && (bool) $user?->can('review', $this->resource),
                'cancel' => (bool) $user?->can('cancel', $this->resource),
            ]),
        ];
    }
}
