<?php

namespace App\Http\Queries;

use App\Http\Filters\DateFilter;
use App\Http\Filters\SearchFilter;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * GET /api/users
 *
 * filter[role]=team_lead,sales_executive  filter[team]=2  filter[active]=true|false
 * filter[created_from]=2026-09-01  filter[created_to]=2026-09-30  filter[search]=text (name, username, email)
 * sort=name (default) | username | email | last_login_at | created_at | id
 *
 * Always starts from visibleTo(user): support and admin see everyone, a team lead only the own team.
 */
final class UserIndexQuery
{
    public const WITH = ['roles', 'team', 'workstation'];

    /**
     * @return QueryBuilder<User>
     */
    public static function make(Request $request): QueryBuilder
    {
        /** @var User $user */
        $user = $request->user();

        return QueryBuilder::for(User::query()->visibleTo($user)->with(self::WITH), $request)
            ->allowedFilters(
                AllowedFilter::callback('role', function (Builder $query, mixed $value): void {
                    $query->whereHas('roles', fn (Builder $roles) => $roles->whereIn('name', Arr::wrap($value)));
                }),
                AllowedFilter::exact('team', 'team_id'),
                AllowedFilter::exact('active', 'is_active'),
                AllowedFilter::custom('created_from', new DateFilter('>='), 'created_at'),
                AllowedFilter::custom('created_to', new DateFilter('<='), 'created_at'),
                AllowedFilter::custom('search', new SearchFilter(['name', 'username', 'email'])),
            )
            ->allowedSorts('name', 'username', 'email', 'last_login_at', 'created_at', 'id')
            ->defaultSort('name', 'id');
    }
}
