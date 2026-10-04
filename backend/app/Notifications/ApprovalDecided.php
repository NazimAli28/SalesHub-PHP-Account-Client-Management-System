<?php

namespace App\Notifications;

use App\Enums\ApprovalStatus;
use App\Models\ApprovalRequest;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Sent to the requester when their request is approved, rejected or fails to apply.
 */
class ApprovalDecided extends Notification
{
    use Queueable;

    public function __construct(public readonly ApprovalRequest $approval) {}

    /**
     * @return list<string>
     */
    public function via(User $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(User $notifiable): array
    {
        return [
            'approval_request_id' => $this->approval->id,
            'action' => $this->approval->action->value,
            'status' => $this->approval->status->value,
            'approvable_type' => $this->approval->approvable_type,
            'approvable_id' => $this->approval->approvable_id,
            'reviewed_by' => $this->approval->reviewer?->name,
            'review_comment' => $this->approval->review_comment,
            'message' => match ($this->approval->status) {
                ApprovalStatus::Approved => 'Your change request was approved.',
                ApprovalStatus::Rejected => 'Your change request was rejected.',
                ApprovalStatus::Failed => 'Your change request could not be applied: '.$this->approval->failure_message,
                default => 'Your change request was updated.',
            },
        ];
    }
}
