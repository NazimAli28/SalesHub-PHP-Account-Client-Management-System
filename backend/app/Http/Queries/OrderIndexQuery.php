<?php

namespace App\Http\Queries;

use App\Http\Filters\DateFilter;
use App\Http\Filters\SearchFilter;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * GET /api/orders
 *
 * filter[status]=pending_payment,in_progress  filter[type]=upsell  filter[owner]=4  filter[team]=2  filter[client]=7
 * filter[ordered_from]=2026-09-01  filter[ordered_to]=2026-09-30  filter[has_overdue]=true
 * filter[search]=text (order number, client name / discord username)
 * sort=-ordered_on (default) | ordered_on | total_cents | order_number | created_at | id
 * include=client,owner,closer,team,platformAccount,parent,items,items.service,payments
 *
 * Always starts from visibleTo(user); every row carries amount_paid, balance and overdue_payments_count.
 */
final class OrderIndexQuery
{
    /**
     * @return QueryBuilder<Order>
     */
    public static function make(Request $request): QueryBuilder
    {
        /** @var User $user */
        $user = $request->user();

        return QueryBuilder::for(self::base($user), $request)
            ->allowedFilters(
                AllowedFilter::exact('status'),
                AllowedFilter::exact('type'),
                AllowedFilter::exact('owner', 'owner_id'),
                AllowedFilter::exact('team', 'team_id'),
                AllowedFilter::exact('client', 'client_id'),
                AllowedFilter::custom('ordered_from', new DateFilter('>='), 'ordered_on'),
                AllowedFilter::custom('ordered_to', new DateFilter('<='), 'ordered_on'),
                AllowedFilter::callback('has_overdue', function (Builder $query, mixed $value): void {
                    $wanted = filter_var($value, FILTER_VALIDATE_BOOLEAN);
                    $wanted
                        ? $query->whereHas('payments', fn ($p) => $p->scopes(['overdue']))
                        : $query->whereDoesntHave('payments', fn ($p) => $p->scopes(['overdue']));
                }),
                AllowedFilter::custom('search', new SearchFilter(['order_number', 'client.name', 'client.discord_username'])),
            )
            ->allowedSorts('ordered_on', 'total_cents', 'order_number', 'created_at', 'id')
            ->defaultSort('-ordered_on', '-id')
            ->allowedIncludes(...OrderResource::INCLUDES);
    }

    /**
     * @return Builder<Order>
     */
    public static function base(User $user): Builder
    {
        return Order::query()->visibleTo($user)->withPaymentTotals()->withOverdueCount()->with(OrderResource::DEFAULT_WITH);
    }
}
