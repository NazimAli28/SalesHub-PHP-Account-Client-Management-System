<?php

namespace App\Notifications;

use App\Models\ApprovalRequest;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Sent to everyone who may review a new request.
 */
class ApprovalSubmitted extends Notification
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
        $requester = $this->approval->requester === null ? 'Someone' : $this->approval->requester->name;

        return [
            'approval_request_id' => $this->approval->id,
            'action' => $this->approval->action->value,
            'status' => $this->approval->status->value,
            'approvable_type' => $this->approval->approvable_type,
            'approvable_id' => $this->approval->approvable_id,
            'message' => "{$requester} submitted a change request for review.",
        ];
    }
}
