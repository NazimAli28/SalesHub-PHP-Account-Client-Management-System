<?php

namespace App\Http\Queries;

use App\Http\Filters\DateFilter;
use App\Http\Filters\SearchFilter;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Spatie\Activitylog\Models\Activity;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * GET /api/audit-log (audit-log.view, admin only; read-only)
 *
 * filter[causer]=4  filter[subject_type]=user  filter[subject_id]=7  filter[log_name]=auth,security
 * filter[event]=login,deactivated  filter[from]=2026-09-01  filter[to]=2026-09-30  filter[search]=text (description)
 * sort=-created_at (default) | created_at | id
 *
 * `subject_type` is the morph alias (lead, user, platform_account, ...).
 */
final class AuditLogIndexQuery
{
    /**
     * @return QueryBuilder<Activity>
     */
    public static function make(Request $request): QueryBuilder
    {
        // Causers and subjects may be soft-deleted users or records; the log still names them.
        $withTrashed = static function (Relation $relation): void {
            if ($relation instanceof MorphTo) {
                $relation->withTrashed();
            }
        };
        $base = Activity::query()->with(['causer' => $withTrashed, 'subject' => $withTrashed]);

        return QueryBuilder::for($base, $request)
            ->allowedFilters(
                AllowedFilter::exact('causer', 'causer_id'),
                AllowedFilter::exact('subject_type'),
                AllowedFilter::exact('subject_id'),
                AllowedFilter::exact('log_name'),
                AllowedFilter::exact('event'),
                AllowedFilter::custom('from', new DateFilter('>='), 'created_at'),
                AllowedFilter::custom('to', new DateFilter('<='), 'created_at'),
                AllowedFilter::custom('search', new SearchFilter(['description'])),
            )
            ->allowedSorts('created_at', 'id')
            ->defaultSort('-created_at', '-id');
    }
}
