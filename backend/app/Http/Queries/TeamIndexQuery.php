<?php

namespace App\Http\Queries;

use App\Http\Filters\SearchFilter;
use App\Http\Resources\TeamResource;
use App\Models\Team;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * GET /api/teams
 *
 * filter[shift]=evening,night  filter[floor]=3  filter[team_lead]=4  filter[search]=text (name)
 * sort=name (default) | floor | shift | created_at | id
 * include=members,workstations   (members_count and workstations_count are always present)
 *
 * Teams are not scoped: every signed-in role may read them (teams.view).
 */
final class TeamIndexQuery
{
    /**
     * @return QueryBuilder<Team>
     */
    public static function make(Request $request): QueryBuilder
    {
        return QueryBuilder::for(self::base(), $request)
            ->allowedFilters(
                AllowedFilter::exact('shift'),
                AllowedFilter::exact('floor'),
                AllowedFilter::exact('team_lead', 'team_lead_id'),
                AllowedFilter::custom('search', new SearchFilter(['name'])),
            )
            ->allowedSorts('name', 'floor', 'shift', 'created_at', 'id')
            ->defaultSort('name', 'id')
            ->allowedIncludes(...TeamResource::INCLUDES);
    }

    /**
     * @return Builder<Team>
     */
    public static function base(): Builder
    {
        return Team::query()->with(TeamResource::DEFAULT_WITH)->withCount(['members', 'workstations']);
    }
}
