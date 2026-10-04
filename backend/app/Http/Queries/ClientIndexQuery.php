<?php

namespace App\Http\Queries;

use App\Http\Filters\DateFilter;
use App\Http\Filters\SearchFilter;
use App\Http\Resources\ClientResource;
use App\Models\Client;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * GET /api/clients
 *
 * filter[status]=active,nurturing  filter[owner]=4  filter[has_open_orders]=true
 * filter[created_from]=2026-09-01  filter[created_to]=2026-09-30  filter[search]=text (name, email, discord username)
 * sort=-created_at (default) | created_at | name | discord_username | expected_upsell_on | nurturing_rating | id
 * include=owner,leads,orders
 *
 * Always starts from visibleTo(user); every row carries `lifetime_value` (paid payments).
 */
final class ClientIndexQuery
{
    /**
     * @return QueryBuilder<Client>
     */
    public static function make(Request $request): QueryBuilder
    {
        /** @var User $user */
        $user = $request->user();

        return QueryBuilder::for(Client::query()->visibleTo($user)->withLifetimeValue()->with(ClientResource::DEFAULT_WITH), $request)
            ->allowedFilters(
                AllowedFilter::exact('status'),
                AllowedFilter::exact('owner', 'owner_id'),
                AllowedFilter::callback('has_open_orders', function (Builder $query, mixed $value): void {
                    $wanted = filter_var($value, FILTER_VALIDATE_BOOLEAN);
                    $wanted
                        ? $query->whereHas('orders', fn ($o) => $o->scopes(['open']))
                        : $query->whereDoesntHave('orders', fn ($o) => $o->scopes(['open']));
                }),
                AllowedFilter::custom('created_from', new DateFilter('>='), 'created_at'),
                AllowedFilter::custom('created_to', new DateFilter('<='), 'created_at'),
                AllowedFilter::custom('search', new SearchFilter(['name', 'email', 'discord_username'])),
            )
            ->allowedSorts('created_at', 'name', 'discord_username', 'expected_upsell_on', 'nurturing_rating', 'id')
            ->defaultSort('-created_at', '-id')
            ->allowedIncludes(...ClientResource::INCLUDES);
    }
}
