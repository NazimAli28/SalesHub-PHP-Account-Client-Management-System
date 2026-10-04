<?php

namespace App\Http\Queries;

use App\Http\Filters\DateFilter;
use App\Models\ApprovalRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * GET /api/approvals
 *
 * filter[status]=pending,failed  filter[action]=update  filter[type]=lead,client  filter[record]=42
 * filter[requester]=4  filter[created_from]=2026-09-01  filter[created_to]=2026-09-30
 * filter[reviewable]=true (only requests the current user may decide)
 * sort=-created_at (default) | created_at | reviewed_at | id
 *
 * Builds the filtered, sorted, include-aware query; always starts from visibleTo(user).
 */
final class ApprovalIndexQuery
{
    /**
     * @return QueryBuilder<ApprovalRequest>
     */
    public static function make(Request $request): QueryBuilder
    {
        /** @var User $user */
        $user = $request->user();

        return QueryBuilder::for(ApprovalRequest::query()->visibleTo($user)->with(['requester', 'reviewer']), $request)
            ->allowedFilters(
                AllowedFilter::exact('status'),
                AllowedFilter::exact('action'),
                AllowedFilter::exact('type', 'approvable_type'),
                AllowedFilter::exact('record', 'approvable_id'),
                AllowedFilter::exact('requester', 'requested_by_id'),
                AllowedFilter::custom('created_from', new DateFilter('>='), 'created_at'),
                AllowedFilter::custom('created_to', new DateFilter('<='), 'created_at'),
                AllowedFilter::callback('reviewable', function (Builder $query, mixed $value) use ($user): void {
                    if ($value === true || $value === '1') {
                        $query->scopes(['reviewableBy' => [$user]]);
                    }
                }),
            )
            ->allowedSorts('created_at', 'reviewed_at', 'id')
            ->defaultSort('-created_at', '-id');
    }
}
