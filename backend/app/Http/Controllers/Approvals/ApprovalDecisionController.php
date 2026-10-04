<?php

namespace App\Http\Controllers\Approvals;

use App\Actions\Approvals\ApproveRequest;
use App\Actions\Approvals\CancelRequest;
use App\Actions\Approvals\RejectRequest;
use App\Http\Controllers\Controller;
use App\Http\Requests\Approvals\ApproveApprovalRequest;
use App\Http\Requests\Approvals\RejectApprovalRequest;
use App\Http\Resources\ApprovalRequestResource;
use App\Models\ApprovalRequest;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Maker-checker decisions. 403 = not allowed to review (own request, wrong team/type),
 * 409 = already decided, or the record changed since submission (request becomes `failed`).
 */
class ApprovalDecisionController extends Controller
{
    public function approve(ApproveApprovalRequest $request, ApprovalRequest $approval, ApproveRequest $approve): ApprovalRequestResource
    {
        /** @var User $user */
        $user = $request->user();

        return ApprovalRequestResource::make($approve->handle($approval, $user, $request->comment()));
    }

    public function reject(RejectApprovalRequest $request, ApprovalRequest $approval, RejectRequest $reject): ApprovalRequestResource
    {
        /** @var User $user */
        $user = $request->user();

        return ApprovalRequestResource::make($reject->handle($approval, $user, $request->comment()));
    }

    public function cancel(Request $request, ApprovalRequest $approval, CancelRequest $cancel): ApprovalRequestResource
    {
        Gate::authorize('cancel', $approval);

        /** @var User $user */
        $user = $request->user();

        return ApprovalRequestResource::make($cancel->handle($approval, $user));
    }
}
