<?php

namespace App\Http\Queries;

use App\Http\Filters\SearchFilter;
use App\Http\Resources\WorkstationResource;
use App\Models\Workstation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * GET /api/workstations
 *
 * filter[team]=2  filter[active]=true|false  filter[search]=text (code, label)
 * sort=code (default) | label | created_at | id
 * include=users   (users_count and platform_accounts_count are always present)
 *
 * Workstations are not scoped: every signed-in role may read them (workstations.view).
 */
final class WorkstationIndexQuery
{
    /**
     * @return QueryBuilder<Workstation>
     */
    public static function make(Request $request): QueryBuilder
    {
        return QueryBuilder::for(self::base(), $request)
            ->allowedFilters(
                AllowedFilter::exact('team', 'team_id'),
                AllowedFilter::exact('active', 'is_active'),
                AllowedFilter::custom('search', new SearchFilter(['code', 'label'])),
            )
            ->allowedSorts('code', 'label', 'created_at', 'id')
            ->defaultSort('code', 'id')
            ->allowedIncludes(...WorkstationResource::INCLUDES);
    }

    /**
     * @return Builder<Workstation>
     */
    public static function base(): Builder
    {
        return Workstation::query()->with(WorkstationResource::DEFAULT_WITH)->withCount(['users', 'platformAccounts']);
    }
}
