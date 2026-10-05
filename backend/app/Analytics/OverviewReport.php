<?php

namespace App\Analytics;

use App\Enums\AccountStanding;
use App\Enums\ClientStatus;
use App\Enums\LeadStage;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\RoleName;
use App\Models\ApprovalRequest;
use App\Models\Client;
use App\Models\Lead;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PlatformAccount;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Builds the dashboard numbers for one user, scope and date range.
 *
 * Every query starts from the model's `visibleTo` scope (so the numbers match what the user can open) and is then
 * narrowed to the analytics scope. Date bucketing happens in PHP on `DATE(column)` groups, which works on SQLite and MySQL.
 * Amounts are integer cents in the order currency (the demo data is single-currency USD).
 */
final class OverviewReport
{
    private const CURRENCY = 'USD';

    /**
     * @return array<string, mixed>
     */
    public function build(AnalyticsScope $scope, DateRange $range): array
    {
        $previous = $range->previous();
        $collected = $this->collectedCents($scope, $range);
        $previousCollected = $this->collectedCents($scope, $previous);
        $won = $this->wonTotals($scope, $range);
        $previousWon = $this->wonTotals($scope, $previous);
        $cohort = $this->cohort($scope, $range);
        $previousCohort = $this->cohort($scope, $previous);
        $aov = $this->averageOrderCents($scope, $range);
        $previousAov = $this->averageOrderCents($scope, $previous);
        $overdue = $this->overdue($scope);

        return [
            'range' => [
                'from' => $range->from,
                'to' => $range->to,
                'previous_from' => $range->previousFrom(),
                'previous_to' => $range->previousTo(),
                'bucket' => $range->bucket(),
                'days' => $range->days(),
            ],
            'currency' => self::CURRENCY,
            'kpis' => [
                'revenue_collected' => $this->kpi($collected, $previousCollected),
                'won_value' => $this->kpi($won['cents'], $previousWon['cents']),
                'won_count' => $this->kpi($won['count'], $previousWon['count']),
                'new_leads' => $this->kpi($cohort['total'], $previousCohort['total']),
                'conversion_rate' => $this->kpi($cohort['rate'], $previousCohort['rate']),
                'average_order_value' => $this->kpi($aov, $previousAov),
                'overdue_payments' => ['count' => $overdue['count'], 'amount_cents' => $overdue['cents']],
                'pending_approvals' => $this->pendingApprovals($scope->user),
                'active_clients' => $this->activeClients($scope),
            ],
            'funnel' => $this->funnel($scope, $range),
            'revenue_series' => $this->series($scope, $range),
            'leaderboard' => $scope->showsLeaderboard() ? $this->leaderboard($scope, $range) : null,
            'account_health' => $this->accountHealth($scope),
            'upcoming_payments' => $this->upcomingPayments($scope),
        ];
    }

    /**
     * @return array{value: int|float, previous: int|float, change_pct: float|null}
     */
    private function kpi(int|float $value, int|float $previous): array
    {
        return [
            'value' => $value,
            'previous' => $previous,
            'change_pct' => $previous == 0 ? null : round((($value - $previous) / $previous) * 100, 1),
        ];
    }

    /**
     * @return Builder<Lead>
     */
    private function leads(AnalyticsScope $scope): Builder
    {
        $query = Lead::query()->visibleTo($scope->user);

        if ($scope->userIds !== null) {
            $query->whereIn('leads.owner_id', $scope->userIds);
        }

        return $query;
    }

    /**
     * @return Builder<Order>
     */
    private function orders(AnalyticsScope $scope): Builder
    {
        $query = Order::query()->visibleTo($scope->user);

        if ($scope->userIds !== null) {
            $query->whereIn('orders.owner_id', $scope->userIds);
        }

        return $query;
    }

    /**
     * @return Builder<Payment>
     */
    private function payments(AnalyticsScope $scope): Builder
    {
        return Payment::query()
            ->visibleTo($scope->user)
            ->whereIn('payments.order_id', $this->orders($scope)->select('orders.id'));
    }

    /**
     * @return Builder<Payment>
     */
    private function paidIn(AnalyticsScope $scope, DateRange $range): Builder
    {
        return $this->payments($scope)
            ->where('payments.status', PaymentStatus::Paid->value)
            ->whereBetween('payments.paid_at', [$range->startsAt(), $range->endsAt()]);
    }

    /**
     * @return Builder<Lead>
     */
    private function wonIn(AnalyticsScope $scope, DateRange $range): Builder
    {
        return $this->leads($scope)
            ->where('leads.stage', LeadStage::Won->value)
            ->whereBetween('leads.stage_changed_at', [$range->startsAt(), $range->endsAt()]);
    }

    private function collectedCents(AnalyticsScope $scope, DateRange $range): int
    {
        return (int) $this->paidIn($scope, $range)->sum('payments.amount_cents');
    }

    /**
     * @return array{count: int, cents: int}
     */
    private function wonTotals(AnalyticsScope $scope, DateRange $range): array
    {
        $row = $this->wonIn($scope, $range)
            ->selectRaw('count(*) as won_count, coalesce(sum(leads.estimated_value_cents), 0) as won_cents')
            ->first();

        return ['count' => (int) ($row?->getAttribute('won_count') ?? 0), 'cents' => (int) ($row?->getAttribute('won_cents') ?? 0)];
    }

    /**
     * Leads created (first contacted) in the range and how many of them are won now.
     *
     * @return array{total: int, won: int, rate: float}
     */
    private function cohort(AnalyticsScope $scope, DateRange $range): array
    {
        $row = $this->leads($scope)
            ->whereBetween('leads.contacted_on', [$range->from->toDateString(), $range->to->toDateString()])
            ->selectRaw('count(*) as total, coalesce(sum(case when leads.stage = ? then 1 else 0 end), 0) as won', [LeadStage::Won->value])
            ->first();

        $total = (int) ($row?->getAttribute('total') ?? 0);
        $won = (int) ($row?->getAttribute('won') ?? 0);

        return ['total' => $total, 'won' => $won, 'rate' => $total === 0 ? 0.0 : round($won / $total * 100, 1)];
    }

    private function averageOrderCents(AnalyticsScope $scope, DateRange $range): int
    {
        return (int) round((float) $this->orders($scope)
            ->whereBetween('orders.ordered_on', [$range->from->toDateString(), $range->to->toDateString()])
            ->whereNotIn('orders.status', [OrderStatus::Cancelled->value, OrderStatus::Refunded->value])
            ->avg('orders.total_cents'));
    }

    /**
     * @return array{count: int, cents: int}
     */
    private function overdue(AnalyticsScope $scope): array
    {
        $row = $this->payments($scope)
            ->overdue()
            ->selectRaw('count(*) as overdue_count, coalesce(sum(payments.amount_cents), 0) as overdue_cents')
            ->first();

        return ['count' => (int) ($row?->getAttribute('overdue_count') ?? 0), 'cents' => (int) ($row?->getAttribute('overdue_cents') ?? 0)];
    }

    /**
     * @return array{reviewable: int, submitted: int}
     */
    private function pendingApprovals(User $user): array
    {
        $row = ApprovalRequest::query()->reviewableBy($user)->count();

        return [
            'reviewable' => $row,
            'submitted' => ApprovalRequest::query()
                ->where('status', 'pending')
                ->where('requested_by_id', $user->id)
                ->count(),
        ];
    }

    private function activeClients(AnalyticsScope $scope): int
    {
        $query = Client::query()->visibleTo($scope->user)->where('clients.status', ClientStatus::Active->value);

        if ($scope->userIds !== null) {
            $query->whereIn('clients.owner_id', $scope->userIds);
        }

        return $query->count();
    }

    /**
     * @return list<array{stage: LeadStage, count: int}>
     */
    private function funnel(AnalyticsScope $scope, DateRange $range): array
    {
        $counts = $this->leads($scope)
            ->whereBetween('leads.contacted_on', [$range->from->toDateString(), $range->to->toDateString()])
            ->selectRaw('leads.stage as stage_value, count(*) as stage_count')
            ->groupBy('leads.stage')
            ->pluck('stage_count', 'stage_value');

        return array_map(
            fn (LeadStage $stage): array => ['stage' => $stage, 'count' => (int) ($counts[$stage->value] ?? 0)],
            LeadStage::ordered(),
        );
    }

    /**
     * @return list<array{date: string, collected_cents: int, won_cents: int}>
     */
    private function series(AnalyticsScope $scope, DateRange $range): array
    {
        $points = [];
        foreach ($range->bucketKeys() as $key) {
            $points[$key] = ['date' => $key, 'collected_cents' => 0, 'won_cents' => 0];
        }

        $collected = $this->paidIn($scope, $range)
            ->selectRaw('DATE(payments.paid_at) as day, sum(payments.amount_cents) as cents')
            ->groupBy(DB::raw('DATE(payments.paid_at)'))
            ->pluck('cents', 'day');

        $won = $this->wonIn($scope, $range)
            ->selectRaw('DATE(leads.stage_changed_at) as day, sum(leads.estimated_value_cents) as cents')
            ->groupBy(DB::raw('DATE(leads.stage_changed_at)'))
            ->pluck('cents', 'day');

        foreach (['collected_cents' => $collected, 'won_cents' => $won] as $field => $byDay) {
            foreach ($byDay as $day => $cents) {
                $key = $range->bucketStart(CarbonImmutable::parse((string) $day))->toDateString();

                if (isset($points[$key])) {
                    $points[$key][$field] += (int) $cents;
                }
            }
        }

        return array_values($points);
    }

    /**
     * Top 5 sales executives in scope by revenue collected, then won leads.
     *
     * @return list<array{user: array{id: int, name: string}, collected_cents: int, won_leads: int}>
     */
    private function leaderboard(AnalyticsScope $scope, DateRange $range): array
    {
        $collected = $this->paidIn($scope, $range)
            ->join('orders', 'orders.id', '=', 'payments.order_id')
            ->selectRaw('orders.owner_id as agent_id, sum(payments.amount_cents) as cents')
            ->groupBy('orders.owner_id')
            ->pluck('cents', 'agent_id');

        $won = $this->wonIn($scope, $range)
            ->selectRaw('leads.owner_id as agent_id, count(*) as won_count')
            ->groupBy('leads.owner_id')
            ->pluck('won_count', 'agent_id');

        $rows = [];
        foreach ($collected->keys()->merge($won->keys())->unique() as $agentId) {
            $rows[(int) $agentId] = [
                'collected_cents' => (int) ($collected[$agentId] ?? 0),
                'won_leads' => (int) ($won[$agentId] ?? 0),
            ];
        }

        $agents = User::query()
            ->whereIn('id', array_keys($rows))
            ->role(RoleName::SalesExecutive->value)
            ->get(['id', 'name'])
            ->keyBy('id');

        $board = [];
        foreach ($rows as $agentId => $row) {
            $agent = $agents->get($agentId);
            if ($agent !== null) {
                $board[] = ['user' => ['id' => $agentId, 'name' => (string) $agent->name], ...$row];
            }
        }

        usort($board, fn (array $a, array $b): int => [$b['collected_cents'], $b['won_leads'], $a['user']['id']]
            <=> [$a['collected_cents'], $a['won_leads'], $b['user']['id']]);

        return array_slice($board, 0, 5);
    }

    /**
     * @return list<array{standing: AccountStanding, count: int}>|null
     */
    private function accountHealth(AnalyticsScope $scope): ?array
    {
        $user = $scope->user;

        if (! ($user->can('platform-accounts.view-all') || $user->can('platform-accounts.view-team') || $user->can('platform-accounts.view-own'))) {
            return null;
        }

        $query = PlatformAccount::query()->visibleTo($user);

        if ($scope->teamId !== null) {
            $query->whereHas('workstation', fn (Builder $q) => $q->where('team_id', $scope->teamId));
        }

        $counts = $query
            ->selectRaw('platform_accounts.standing as standing_value, count(*) as standing_count')
            ->groupBy('platform_accounts.standing')
            ->pluck('standing_count', 'standing_value');

        return array_map(
            fn (AccountStanding $standing): array => ['standing' => $standing, 'count' => (int) ($counts[$standing->value] ?? 0)],
            AccountStanding::cases(),
        );
    }

    /**
     * @return list<Payment>
     */
    private function upcomingPayments(AnalyticsScope $scope): array
    {
        return array_values($this->payments($scope)
            ->where('payments.status', PaymentStatus::Scheduled->value)
            ->where('payments.due_date', '>=', today()->toDateString())
            ->with(['order:id,order_number,client_id', 'order.client:id,name'])
            ->orderBy('payments.due_date')
            ->orderBy('payments.id')
            ->limit(5)
            ->get()
            ->all());
    }
}
