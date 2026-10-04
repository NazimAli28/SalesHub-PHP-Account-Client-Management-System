<?php

use App\Enums\AccountStanding;
use App\Enums\LeadStage;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\RoleName;
use App\Models\ApprovalRequest;
use App\Models\Lead;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PlatformAccount;
use Illuminate\Support\Carbon;

function analyticsPaid(Order $order, int $cents, string $paidAt): Payment
{
    return Payment::factory()->create([
        'order_id' => $order->id,
        'sequence' => $order->payments()->count() + 1,
        'amount_cents' => $cents,
        'status' => PaymentStatus::Paid,
        'paid_at' => $paidAt,
        'due_date' => substr($paidAt, 0, 10),
    ]);
}

function analyticsOrder($owner, int $totalCents, string $orderedOn, ?OrderStatus $status = null): Order
{
    return Order::factory()->create([
        'owner_id' => $owner->id,
        'team_id' => $owner->team_id,
        'total_cents' => $totalCents,
        'subtotal_cents' => $totalCents,
        'ordered_on' => $orderedOn,
        'status' => $status ?? OrderStatus::InProgress,
    ]);
}

function analyticsLead($owner, LeadStage $stage, string $contactedOn, int $value = 10000, ?string $changedAt = null): Lead
{
    return Lead::factory()->create([
        'owner_id' => $owner->id,
        'stage' => $stage,
        'contacted_on' => $contactedOn,
        'estimated_value_cents' => $value,
        'stage_changed_at' => $changedAt ?? $contactedOn.' 10:00:00',
    ]);
}

beforeEach(function () {
    Carbon::setTestNow('2026-10-05 12:00:00');

    $this->alpha = $this->makeTeamWithMembers(2);
    $this->bravo = $this->makeTeamWithMembers(1);
    [$a1, $a2] = $this->alpha->agents;
    $b1 = $this->bravo->agent(0);

    // Current range 2026-09-06..2026-10-05 (30 days); previous 2026-08-07..2026-09-05.
    $o1 = analyticsOrder($a1, 50000, '2026-09-20');
    analyticsPaid($o1, 30000, '2026-09-21 09:00:00');
    analyticsPaid($o1, 10000, '2026-10-01 09:00:00');
    $o2 = analyticsOrder($a2, 20000, '2026-09-25');
    analyticsPaid($o2, 20000, '2026-09-26 09:00:00');
    $o3 = analyticsOrder($b1, 90000, '2026-09-28');
    analyticsPaid($o3, 90000, '2026-09-29 09:00:00');
    // previous period
    $o4 = analyticsOrder($a1, 40000, '2026-08-20');
    analyticsPaid($o4, 20000, '2026-08-25 09:00:00');
    // outside both windows
    analyticsPaid(analyticsOrder($a1, 99900, '2026-01-10'), 99900, '2026-01-11 09:00:00');

    // leads created in range: a1 -> 2 (1 won), a2 -> 1 (new), b1 -> 1 (won)
    analyticsLead($a1, LeadStage::Won, '2026-09-10', 30000, '2026-09-22 10:00:00');
    analyticsLead($a1, LeadStage::Quoted, '2026-09-12');
    analyticsLead($a2, LeadStage::New, '2026-09-15');
    analyticsLead($b1, LeadStage::Won, '2026-09-18', 70000, '2026-09-30 10:00:00');
    // previous period lead
    analyticsLead($a1, LeadStage::Lost, '2026-08-15');

    // overdue payments: alpha 2 (150 + 50), bravo 1
    Payment::factory()->create(['order_id' => $o1->id, 'sequence' => 3, 'amount_cents' => 15000, 'status' => PaymentStatus::Scheduled, 'due_date' => '2026-09-30']);
    Payment::factory()->create(['order_id' => $o2->id, 'sequence' => 2, 'amount_cents' => 5000, 'status' => PaymentStatus::Scheduled, 'due_date' => '2026-10-01']);
    Payment::factory()->create(['order_id' => $o3->id, 'sequence' => 2, 'amount_cents' => 7000, 'status' => PaymentStatus::Scheduled, 'due_date' => '2026-10-02']);
    // upcoming
    $this->upcoming = Payment::factory()->create(['order_id' => $o1->id, 'sequence' => 4, 'amount_cents' => 2500, 'status' => PaymentStatus::Scheduled, 'due_date' => '2026-10-10']);
});

afterEach(fn () => Carbon::setTestNow());

it('requires authentication', function () {
    $this->getJson('/api/analytics/overview')->assertUnauthorized();
});

it('computes KPIs for admin across every team', function () {
    $this->actingAsRole(RoleName::Admin);

    $response = $this->getJson('/api/analytics/overview?from=2026-09-06&to=2026-10-05')->assertOk();

    $response
        ->assertJsonPath('data.range.from', '2026-09-06')
        ->assertJsonPath('data.range.to', '2026-10-05')
        ->assertJsonPath('data.range.previous_from', '2026-08-07')
        ->assertJsonPath('data.range.previous_to', '2026-09-05')
        ->assertJsonPath('data.range.bucket', 'day')
        ->assertJsonPath('data.kpis.revenue_collected.value.amount_cents', 150000)
        ->assertJsonPath('data.kpis.revenue_collected.previous.amount_cents', 20000)
        ->assertJsonPath('data.kpis.revenue_collected.change_pct', 650)
        ->assertJsonPath('data.kpis.won_value.value.amount_cents', 100000)
        ->assertJsonPath('data.kpis.won_count.value', 2)
        ->assertJsonPath('data.kpis.new_leads.value', 4)
        ->assertJsonPath('data.kpis.new_leads.previous', 1)
        ->assertJsonPath('data.kpis.conversion_rate.value', 50)
        ->assertJsonPath('data.kpis.conversion_rate.previous', 0)
        ->assertJsonPath('data.kpis.conversion_rate.change_pct', null)
        ->assertJsonPath('data.kpis.average_order_value.value.amount_cents', 53333)
        ->assertJsonPath('data.kpis.overdue_payments.count', 3)
        ->assertJsonPath('data.kpis.overdue_payments.amount.amount_cents', 27000);

    expect($response->json('data.funnel'))->toHaveCount(7);
    expect(collect($response->json('data.funnel'))->pluck('stage.value')->all())
        ->toBe(['new', 'engaged', 'portfolio_shared', 'quoted', 'payment_pending', 'won', 'lost']);
    expect(collect($response->json('data.funnel'))->pluck('count', 'stage.value')->all())
        ->toMatchArray(['new' => 1, 'quoted' => 1, 'won' => 2, 'lost' => 0]);
});

it('buckets the revenue series and sums to the KPI', function () {
    $this->actingAsRole(RoleName::Support);

    $series = $this->getJson('/api/analytics/overview?from=2026-09-06&to=2026-10-05')
        ->assertOk()->json('data.revenue_series');

    expect(array_sum(array_column($series, 'collected_cents')))->toBe(150000)
        ->and(array_sum(array_column($series, 'won_cents')))->toBe(100000)
        ->and($series[0]['date'])->toBe('2026-09-06')
        ->and($series[1]['date'])->toBe('2026-09-07');
});

it('uses daily buckets for short ranges and monthly for long ones', function () {
    $this->actingAsRole(RoleName::Admin);

    $daily = $this->getJson('/api/analytics/overview?from=2026-10-01&to=2026-10-05')->assertOk();
    expect($daily->json('data.range.bucket'))->toBe('day')
        ->and($daily->json('data.revenue_series'))->toHaveCount(5);

    $monthly = $this->getJson('/api/analytics/overview?from=2026-01-01&to=2026-10-05')->assertOk();
    expect($monthly->json('data.range.bucket'))->toBe('month')
        ->and($monthly->json('data.revenue_series'))->toHaveCount(10)
        ->and($monthly->json('data.revenue_series.0.collected_cents'))->toBe(99900);
});

it('defaults to the last 30 days', function () {
    $this->actingAsRole(RoleName::Admin);

    $this->getJson('/api/analytics/overview')->assertOk()
        ->assertJsonPath('data.range.from', '2026-09-06')
        ->assertJsonPath('data.range.to', '2026-10-05')
        ->assertJsonPath('data.range.days', 30);
});

it('limits a team lead to their own team and ranks agents', function () {
    $this->actingAsUser($this->alpha->teamLead);

    $response = $this->getJson('/api/analytics/overview?from=2026-09-06&to=2026-10-05')->assertOk();

    $response
        ->assertJsonPath('data.kpis.revenue_collected.value.amount_cents', 60000)
        ->assertJsonPath('data.kpis.won_count.value', 1)
        ->assertJsonPath('data.kpis.overdue_payments.count', 2);

    $board = $response->json('data.leaderboard');
    expect(collect($board)->pluck('user.id')->all())
        ->toBe([$this->alpha->agent(0)->id, $this->alpha->agent(1)->id])
        ->and($board[0]['collected']['amount_cents'])->toBe(40000)
        ->and($board[0]['won_leads'])->toBe(1);
});

it('lets a team lead filter a member and forbids other teams', function () {
    $this->actingAsUser($this->alpha->teamLead);

    $this->getJson('/api/analytics/overview?from=2026-09-06&to=2026-10-05&user_id='.$this->alpha->agent(1)->id)
        ->assertOk()
        ->assertJsonPath('data.kpis.revenue_collected.value.amount_cents', 20000)
        ->assertJsonPath('data.leaderboard', null);

    $this->getJson('/api/analytics/overview?team_id='.$this->bravo->team->id)->assertForbidden();
    $this->getJson('/api/analytics/overview?user_id='.$this->bravo->agent(0)->id)->assertForbidden();
    $this->getJson('/api/analytics/overview?team_id='.$this->alpha->team->id)->assertOk();
});

it('limits a sales executive to their own records and hides the leaderboard', function () {
    $this->actingAsUser($this->alpha->agent(0));

    $this->getJson('/api/analytics/overview?from=2026-09-06&to=2026-10-05')->assertOk()
        ->assertJsonPath('data.kpis.revenue_collected.value.amount_cents', 40000)
        ->assertJsonPath('data.kpis.new_leads.value', 2)
        ->assertJsonPath('data.kpis.overdue_payments.count', 1)
        ->assertJsonPath('data.leaderboard', null);

    $this->getJson('/api/analytics/overview?user_id='.$this->alpha->agent(1)->id)->assertForbidden();
    $this->getJson('/api/analytics/overview?user_id='.$this->alpha->agent(0)->id)->assertOk();
});

it('filters by team for admin', function () {
    $this->actingAsRole(RoleName::Admin);

    $this->getJson('/api/analytics/overview?from=2026-09-06&to=2026-10-05&team_id='.$this->bravo->team->id)
        ->assertOk()
        ->assertJsonPath('data.kpis.revenue_collected.value.amount_cents', 90000)
        ->assertJsonPath('data.leaderboard.0.user.id', $this->bravo->agent(0)->id);
});

it('lists the next scheduled payments', function () {
    $this->actingAsUser($this->alpha->agent(0));

    $upcoming = $this->getJson('/api/analytics/overview')->assertOk()->json('data.upcoming_payments');

    expect($upcoming)->toHaveCount(1)
        ->and($upcoming[0]['id'])->toBe($this->upcoming->id)
        ->and($upcoming[0]['amount']['amount_cents'])->toBe(2500)
        ->and($upcoming[0]['due_date'])->toBe('2026-10-10')
        ->and($upcoming[0]['order']['order_number'])->toBeString();
});

it('reports account health scoped to the user', function () {
    PlatformAccount::factory()->count(2)->create(['workstation_id' => $this->alpha->station(0)->id, 'standing' => AccountStanding::Active]);
    PlatformAccount::factory()->create(['workstation_id' => $this->alpha->station(1)->id, 'standing' => AccountStanding::Limited]);
    PlatformAccount::factory()->create(['workstation_id' => $this->bravo->station(0)->id, 'standing' => AccountStanding::Spam]);

    $this->actingAsRole(RoleName::Admin);
    $all = collect($this->getJson('/api/analytics/overview')->assertOk()->json('data.account_health'))
        ->pluck('count', 'standing.value')->all();
    expect($all)->toMatchArray(['active' => 2, 'limited' => 1, 'spam' => 1]);

    $this->actingAsUser($this->alpha->teamLead);
    $team = collect($this->getJson('/api/analytics/overview')->assertOk()->json('data.account_health'))
        ->pluck('count', 'standing.value')->all();
    expect($team)->toMatchArray(['active' => 2, 'limited' => 1, 'spam' => 0]);
});

it('counts approvals to review and submitted', function () {
    ApprovalRequest::factory()->create(['requested_by_id' => $this->alpha->agent(0)->id]);
    ApprovalRequest::factory()->create(['requested_by_id' => $this->bravo->agent(0)->id]);

    $this->actingAsUser($this->alpha->teamLead);
    $this->getJson('/api/analytics/overview')->assertOk()
        ->assertJsonPath('data.kpis.pending_approvals.reviewable', 1)
        ->assertJsonPath('data.kpis.pending_approvals.submitted', 0);

    $this->actingAsUser($this->alpha->agent(0));
    $this->getJson('/api/analytics/overview')->assertOk()
        ->assertJsonPath('data.kpis.pending_approvals.reviewable', 0)
        ->assertJsonPath('data.kpis.pending_approvals.submitted', 1);
});

it('validates the query', function (string $query, string $field) {
    $this->actingAsRole(RoleName::Admin);

    $this->getJson('/api/analytics/overview?'.$query)->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    'bad date' => ['from=yesterday', 'from'],
    'to before from' => ['from=2026-09-10&to=2026-09-01', 'to'],
    'range too long' => ['from=2025-01-01&to=2026-10-05', 'to'],
    'unknown team' => ['team_id=999999', 'team_id'],
    'unknown user' => ['user_id=999999', 'user_id'],
]);

it('uses weekly buckets starting on Monday for a 90 day range', function () {
    $this->actingAsRole(RoleName::Admin);

    $series = $this->getJson('/api/analytics/overview?from=2026-07-08&to=2026-10-05')->assertOk()
        ->assertJsonPath('data.range.bucket', 'week')
        ->json('data.revenue_series');

    expect($series[0]['date'])->toBe('2026-07-08')
        ->and($series[1]['date'])->toBe('2026-07-13')
        ->and(array_sum(array_column($series, 'collected_cents')))->toBe(170000);
});
