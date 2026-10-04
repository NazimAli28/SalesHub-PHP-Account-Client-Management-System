<?php

namespace App\Http\Controllers\Approvals;

use App\Actions\Approvals\SubmitChangeRequest;
use App\Http\Controllers\Controller;
use App\Http\Requests\Approvals\RequestAccountsRequest;
use App\Http\Resources\ApprovalRequestResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * POST /api/platform-accounts/request-new: queue a `request_accounts` approval (202).
 */
class AccountRequestController extends Controller
{
    public function __invoke(RequestAccountsRequest $request, SubmitChangeRequest $submit): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $approval = $submit->requestAccounts(
            $user,
            $request->workstationId(),
            (int) $request->validated('quantity'),
            $request->validated('note'),
            $request->validated('reason'),
        );

        return ApprovalRequestResource::make($approval)->response()->setStatusCode(Response::HTTP_ACCEPTED);
    }
}
