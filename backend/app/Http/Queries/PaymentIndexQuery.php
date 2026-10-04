<?php

namespace App\Http\Queries;

use App\Http\Filters\DateFilter;
use App\Http\Filters\SearchFilter;
use App\Http\Resources\PaymentResource;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * GET /api/payments (due / overdue lists) and GET /api/orders/{order}/payments
 *
 * filter[status]=scheduled,paid  filter[overdue]=true  filter[order]=12  filter[client]=7  filter[owner]=4  filter[team]=2
 * filter[due_from]=2026-10-01  filter[due_to]=2026-10-07  filter[paid_from]=...  filter[paid_to]=...
 * filter[search]=text (order number, client name / discord username, reference)
 * sort=due_date (default) | -due_date | amount_cents | paid_at | sequence | created_at | id
 * include=order,order.client,recordedBy
 *
 * Always starts from visibleTo(user) (scope follows the order); `owner`, `team` and `client` filter by the order's.
 * Every row carries its order summary (number, status, client).
 */
final class PaymentIndexQuery
{
    /**
     * @return QueryBuilder<Payment>
     */
    public static function make(Request $request, ?Order $order = null): QueryBuilder
    {
        /** @var User $user */
        $user = $request->user();

        $base = Payment::query()
            ->visibleTo($user)
            ->with([...PaymentResource::DEFAULT_WITH, 'order.client'])
            ->when($order !== null, fn ($q) => $q->where('payments.order_id', $order?->id));

        return QueryBuilder::for($base, $request)
            ->allowedFilters(
                AllowedFilter::exact('status'),
                AllowedFilter::exact('order', 'order_id'),
                AllowedFilter::callback('overdue', function (Builder $query, mixed $value): void {
                    filter_var($value, FILTER_VALIDATE_BOOLEAN)
                        ? $query->scopes(['overdue'])
                        : $query->whereNot(fn ($q) => $q->scopes(['overdue']));
                }),
                AllowedFilter::callback('client', fn (Builder $q, mixed $v) => $q->whereHas('order', fn ($o) => $o->whereIn('client_id', (array) $v))),
                AllowedFilter::callback('owner', fn (Builder $q, mixed $v) => $q->whereHas('order', fn ($o) => $o->whereIn('owner_id', (array) $v))),
                AllowedFilter::callback('team', fn (Builder $q, mixed $v) => $q->whereHas('order', fn ($o) => $o->whereIn('team_id', (array) $v))),
                AllowedFilter::custom('due_from', new DateFilter('>='), 'due_date'),
                AllowedFilter::custom('due_to', new DateFilter('<='), 'due_date'),
                AllowedFilter::custom('paid_from', new DateFilter('>='), 'paid_at'),
                AllowedFilter::custom('paid_to', new DateFilter('<='), 'paid_at'),
                AllowedFilter::custom('search', new SearchFilter(['reference', 'order.order_number', 'order.client.name', 'order.client.discord_username'])),
            )
            ->allowedSorts('due_date', 'amount_cents', 'paid_at', 'sequence', 'created_at', 'id')
            ->defaultSort('due_date', 'id')
            ->allowedIncludes(...PaymentResource::INCLUDES);
    }
}
