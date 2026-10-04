<?php

use App\Enums\RoleName;
use App\Models\Payment;
use Tests\Support\OrderFixtures;

beforeEach(function () {
    $this->alpha = $this->makeTeamWithMembers(2);
    $this->bravo = $this->makeTeamWithMembers(1);

    $this->mineOrder = OrderFixtures::make($this->alpha->agent(0), 100000);
    $this->teammateOrder = OrderFixtures::make($this->alpha->agent(1), 100000);
    $this->otherOrder = OrderFixtures::make($this->bravo->agent(0), 100000);

    $this->overdue = Payment::factory()->overdue()->create(['order_id' => $this->mineOrder->id, 'sequence' => 1, 'amount_cents' => 10000]);
    $this->today = Payment::factory()->create(['order_id' => $this->mineOrder->id, 'sequence' => 2, 'amount_cents' => 20000, 'due_date' => today()->toDateString()]);
    $this->paid = Payment::factory()->paid()->create(['order_id' => $this->teammateOrder->id, 'sequence' => 1, 'amount_cents' => 30000]);
    $this->foreign = Payment::factory()->create(['order_id' => $this->otherOrder->id, 'sequence' => 1, 'amount_cents' => 5000, 'due_date' => today()->addDays(3)->toDateString()]);
});

function paymentIds($response): array
{
    return collect($response->json('data'))->pluck('id')->sort()->values()->all();
}

it('scopes the global list per role', function () {
    $this->actingAsUser($this->alpha->agent(0));
    expect(paymentIds($this->getJson('/api/payments')->assertOk()))->toBe([$this->overdue->id, $this->today->id]);

    $this->actingAsUser($this->alpha->teamLead);
    expect(paymentIds($this->getJson('/api/payments')->assertOk()))->toBe([$this->overdue->id, $this->today->id, $this->paid->id]);

    $this->actingAsRole(RoleName::Support);
    expect(paymentIds($this->getJson('/api/payments')->assertOk()))->toBe([$this->overdue->id, $this->today->id, $this->paid->id, $this->foreign->id]);
});

it('lists overdue and due-today payments for the tasks view', function () {
    $this->actingAsRole(RoleName::Support);

    expect(paymentIds($this->getJson('/api/payments?filter[overdue]=true')->assertOk()))->toBe([$this->overdue->id])
        ->and(paymentIds($this->getJson('/api/payments?filter[status]=scheduled&filter[due_from]='.today()->toDateString().'&filter[due_to]='.today()->toDateString())->assertOk()))->toBe([$this->today->id])
        ->and(paymentIds($this->getJson('/api/payments?filter[status]=paid')->assertOk()))->toBe([$this->paid->id])
        ->and(paymentIds($this->getJson('/api/payments?filter[overdue]=false&filter[status]=scheduled')->assertOk()))->toBe([$this->today->id, $this->foreign->id]);
});

it('filters by order owner, team, client and order and searches order numbers', function () {
    $this->actingAsRole(RoleName::Support);

    expect(paymentIds($this->getJson('/api/payments?filter[owner]='.$this->alpha->agent(1)->id)->assertOk()))->toBe([$this->paid->id])
        ->and(paymentIds($this->getJson('/api/payments?filter[team]='.$this->bravo->team->id)->assertOk()))->toBe([$this->foreign->id])
        ->and(paymentIds($this->getJson('/api/payments?filter[client]='.$this->mineOrder->client_id)->assertOk()))->toBe([$this->overdue->id, $this->today->id])
        ->and(paymentIds($this->getJson('/api/payments?filter[order]='.$this->teammateOrder->id)->assertOk()))->toBe([$this->paid->id])
        ->and(paymentIds($this->getJson('/api/payments?filter[search]='.$this->otherOrder->order_number)->assertOk()))->toBe([$this->foreign->id]);
});

it('sorts by due date by default and rejects unknown parameters', function () {
    $this->actingAsRole(RoleName::Support);

    $due = fn ($r) => collect($r->json('data'))->pluck('id')->all();
    expect($due($this->getJson('/api/payments?filter[status]=scheduled')))->toBe([$this->overdue->id, $this->today->id, $this->foreign->id])
        ->and($due($this->getJson('/api/payments?filter[status]=scheduled&sort=-amount_cents')))->toBe([$this->today->id, $this->overdue->id, $this->foreign->id]);

    $this->getJson('/api/payments?filter[bogus]=1')->assertStatus(400);
    $this->getJson('/api/payments?sort=bogus')->assertStatus(400);
    $this->getJson('/api/payments?include=bogus')->assertStatus(400);
    $this->getJson('/api/payments?page[size]=2')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.total', 4);
});

it('formats values and derives is_overdue', function () {
    $this->actingAsUser($this->alpha->agent(0));

    $this->getJson('/api/payments?include=recordedBy')
        ->assertOk()
        ->assertJsonPath('data.0.id', $this->overdue->id)
        ->assertJsonPath('data.0.amount.formatted', '$100.00')
        ->assertJsonPath('data.0.status.value', 'scheduled')
        ->assertJsonPath('data.0.is_overdue', true)
        ->assertJsonPath('data.0.order.order_number', $this->mineOrder->order_number)
        ->assertJsonPath('data.0.order.client.id', $this->mineOrder->client_id)
        ->assertJsonPath('data.1.is_overdue', false);
});

it('lists the payments of one order, 403 for an order out of scope', function () {
    $this->actingAsUser($this->alpha->agent(0));

    expect(paymentIds($this->getJson("/api/orders/{$this->mineOrder->id}/payments")->assertOk()))->toBe([$this->overdue->id, $this->today->id]);
    $this->getJson("/api/orders/{$this->otherOrder->id}/payments")->assertForbidden();
});
