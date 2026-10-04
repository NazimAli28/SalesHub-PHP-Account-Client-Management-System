<?php

namespace App\Http\Controllers\Concerns;

use App\Actions\Approvals\SubmitChangeRequest;
use App\Http\Resources\ApprovalRequestResource;
use App\Models\User;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Symfony\Component\HttpFoundation\Response;

/**
 * The update-vs-queue rule (auth-and-permissions 4.2), implemented once for every module:
 *
 *   can('update')        -> run $apply, return its response (200, or 204 for deletes)
 *   can('requestChange') -> queue an ApprovalRequest, return 202 with ApprovalRequestResource
 *   otherwise            -> 403
 *
 * The record's policy must define update, delete and requestChange (ScopedResourcePolicy does).
 */
trait RoutesChangesThroughApprovals
{
    /**
     * 403 unless the user may either write directly or request the change. Call it before validating
     * input that a Form Request does not cover (Form Requests use canChangeOrRequest() in authorize()).
     *
     * @param  'update'|'delete'  $ability
     *
     * @throws AuthorizationException
     */
    protected function authorizeChangeOrRequest(User $user, string $ability, Model $record): void
    {
        if (! $user->can($ability, $record) && ! $user->can('requestChange', $record)) {
            throw new AuthorizationException;
        }
    }

    /**
     * @param  array<string, mixed>  $changes  Validated (and normalized) attribute changes.
     * @param  Closure(): (JsonResource|Response)  $apply  The direct write; returns the 200 response.
     * @param  array<string, list<int>>  $relations  BelongsToMany relation name => full new ID list.
     *
     * @throws AuthorizationException
     */
    protected function updateOrRequestChange(
        User $user,
        Model $record,
        array $changes,
        Closure $apply,
        array $relations = [],
        ?string $reason = null,
    ): JsonResource|Response {
        if ($user->can('update', $record)) {
            return $apply();
        }

        if ($user->can('requestChange', $record)) {
            $approval = app(SubmitChangeRequest::class)->update($user, $record, $changes, $relations, $reason);

            return $this->queued($approval);
        }

        throw new AuthorizationException;
    }

    /**
     * @param  Closure(): (JsonResource|Response)  $apply  The direct delete; usually returns response()->noContent().
     *
     * @throws AuthorizationException
     */
    protected function deleteOrRequestDeletion(User $user, Model $record, Closure $apply, ?string $reason = null): JsonResource|Response
    {
        if ($user->can('delete', $record)) {
            return $apply();
        }

        if ($user->can('requestChange', $record)) {
            $approval = app(SubmitChangeRequest::class)->delete($user, $record, $reason);

            return $this->queued($approval);
        }

        throw new AuthorizationException;
    }

    /**
     * 202 Accepted with the queued request.
     */
    protected function queued(Model $approval): JsonResponse
    {
        return ApprovalRequestResource::make($approval)->response()->setStatusCode(Response::HTTP_ACCEPTED);
    }
}
