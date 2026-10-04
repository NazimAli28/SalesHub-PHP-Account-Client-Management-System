<?php

namespace App\Http\Queries;

use App\Http\Filters\DateFilter;
use App\Http\Filters\SearchFilter;
use App\Http\Resources\PlatformAccountResource;
use App\Models\PlatformAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * GET /api/platform-accounts
 *
 * filter[standing]=active,limited  filter[workstation]=3  filter[team]=2  filter[assigned]=1|0
 * filter[batch_from]=2026-09-01  filter[batch_to]=2026-09-30  filter[search]=email or discord username
 * sort=-batch_date (default) | batch_date | standing | standing_changed_at | assigned_at | email | created_at | id
 * include=workstation,workstation.team,socialAccounts
 *
 * Rows carry the workstation (+team) and `social_accounts_count`. Always starts from visibleTo(user).
 */
final class PlatformAccountIndexQuery
{
    /**
     * @return QueryBuilder<PlatformAccount>
     */
    public static function make(Request $request): QueryBuilder
    {
        /** @var User $user */
        $user = $request->user();

        return QueryBuilder::for(
            PlatformAccount::query()->visibleTo($user)->with(PlatformAccountResource::DEFAULT_WITH)->withCount('socialAccounts'),
            $request,
        )
            ->allowedFilters(
                AllowedFilter::exact('standing'),
                AllowedFilter::exact('workstation', 'workstation_id'),
                AllowedFilter::callback('team', function (Builder $query, mixed $value): void {
                    $query->whereHas('workstation', fn (Builder $q) => $q->whereIn('team_id', (array) $value));
                }),
                AllowedFilter::callback('assigned', function (Builder $query, mixed $value): void {
                    $assigned = filter_var(is_array($value) ? end($value) : $value, FILTER_VALIDATE_BOOLEAN);

                    if ($assigned) {
                        $query->whereNotNull('workstation_id');
                    } else {
                        $query->whereNull('workstation_id');
                    }
                }),
                AllowedFilter::custom('batch_from', new DateFilter('>='), 'batch_date'),
                AllowedFilter::custom('batch_to', new DateFilter('<='), 'batch_date'),
                AllowedFilter::custom('search', new SearchFilter(['email', 'discord_username'])),
            )
            ->allowedSorts('batch_date', 'standing', 'standing_changed_at', 'assigned_at', 'email', 'created_at', 'id')
            ->defaultSort('-batch_date', '-id')
            ->allowedIncludes(...PlatformAccountResource::INCLUDES);
    }
}
