<?php

namespace App\Http\Queries;

use App\Enums\LeadStage;
use App\Http\Filters\DateFilter;
use App\Http\Filters\SearchFilter;
use App\Http\Resources\LeadResource;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * GET /api/leads
 *
 * filter[stage]=new,engaged  filter[owner]=4  filter[client]=7  filter[platform_account]=3
 * filter[contacted_from]=2026-09-01  filter[contacted_to]=2026-09-30  filter[search]=text
 * filter[follow_up_from]=2026-10-01  filter[follow_up_to]=2026-10-04  filter[open]=1 (stage not won/lost)
 * sort=-contacted_on (default) | contacted_on | stage_changed_at | next_follow_up_on | estimated_value_cents | created_at
 * include=client,owner,closer,platformAccount,services
 *
 * Builds the filtered, sorted, include-aware query; always starts from visibleTo(user).
 */
final class LeadIndexQuery
{
    /**
     * @return QueryBuilder<Lead>
     */
    public static function make(Request $request): QueryBuilder
    {
        /** @var User $user */
        $user = $request->user();

        return QueryBuilder::for(Lead::query()->visibleTo($user)->with(LeadResource::DEFAULT_WITH), $request)
            ->allowedFilters(
                AllowedFilter::exact('stage'),
                AllowedFilter::exact('owner', 'owner_id'),
                AllowedFilter::exact('client', 'client_id'),
                AllowedFilter::exact('platform_account', 'platform_account_id'),
                AllowedFilter::custom('contacted_from', new DateFilter('>='), 'contacted_on'),
                AllowedFilter::custom('contacted_to', new DateFilter('<='), 'contacted_on'),
                AllowedFilter::custom('follow_up_from', new DateFilter('>='), 'next_follow_up_on'),
                AllowedFilter::custom('follow_up_to', new DateFilter('<='), 'next_follow_up_on'),
                AllowedFilter::callback('open', static function (Builder $query, mixed $value): void {
                    if (filter_var($value, FILTER_VALIDATE_BOOL)) {
                        $query->whereNotIn('stage', [LeadStage::Won->value, LeadStage::Lost->value]);
                    }
                }),
                AllowedFilter::custom('search', new SearchFilter([
                    'last_message', 'lost_note', 'client.discord_username', 'client.name', 'client.email',
                ])),
            )
            ->allowedSorts('contacted_on', 'stage_changed_at', 'next_follow_up_on', 'estimated_value_cents', 'created_at', 'id')
            ->defaultSort('-contacted_on', '-id')
            ->allowedIncludes(...LeadResource::INCLUDES);
    }
}
