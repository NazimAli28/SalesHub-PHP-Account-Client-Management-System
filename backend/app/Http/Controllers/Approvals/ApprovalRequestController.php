<?php

namespace App\Http\Controllers\Approvals;

use App\Enums\ApprovalStatus;
use App\Http\Controllers\Controller;
use App\Http\Queries\ApiPagination;
use App\Http\Queries\ApprovalIndexQuery;
use App\Http\Resources\ApprovalRequestResource;
use App\Models\ApprovalRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class ApprovalRequestController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', ApprovalRequest::class);

        return ApprovalRequestResource::collection(ApiPagination::paginate(ApprovalIndexQuery::make($request), $request));
    }

    /**
     * Detail with the before/after diff and `can: {review, cancel}`.
     */
    public function show(ApprovalRequest $approval): ApprovalRequestResource
    {
        Gate::authorize('view', $approval);

        return ApprovalRequestResource::make($approval->load(['requester', 'reviewer']))->withAbilities();
    }

    /**
     * Badge counts: `reviewable` = pending requests the user may decide, `own` = the user's own pending requests.
     */
    public function pendingCount(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', ApprovalRequest::class);

        /** @var User $user */
        $user = $request->user();

        return response()->json(['data' => [
            'reviewable' => ApprovalRequest::query()->visibleTo($user)->reviewableBy($user)->count(),
            'own' => ApprovalRequest::query()
                ->where('requested_by_id', $user->id)
                ->where('status', ApprovalStatus::Pending->value)
                ->count(),
        ]]);
    }
}
