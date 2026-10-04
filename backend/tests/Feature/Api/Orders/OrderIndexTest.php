<?php

use App\Enums\OrderStatus;
use App\Enums\RoleName;
use App\Models\Payment;
use Tests\Support\OrderFixtures;

beforeEach(function () {
    $this->alpha = $this->makeTeamWithMembers(2);
    $this->bravo = $this->makeTeamWithMembers(1);

    $this->mine = OrderFixtures::make($this->alpha->agent(0), 100000, ['ordered_on' => '2026-09-10']);
    $this->teammate = OrderFixtures::make($this->alpha->agent(1), 50000, ['ordered_on' => '2026-09-20', 'status' => OrderStatus::Completed]);
    $this->other = OrderFixtures::make($this->bravo->agent(0), 70000, ['ordered_on' => '2026-08-01']);
});

function orderIds($response): array
{
    return collect($response->json('data'))->pluck('id')->sort()->values()->all();
}

it('scopes the index per role', function () {
    $this->actingAsUser($this->alpha->agent(0));
    expect(orderIds($this->getJson('/api/orders')->assertOk()))->toBe([$this->mine->id]);

    $this->actingAsUser($this->alpha->teamLead);
    expect(orderIds($this->getJson('/api/orders')->assertOk()))->toBe([$this->mine->id, $this->teammate->id]);

    $this->actingAsRole(RoleName::Support);
    expect(orderIds($this->getJson('/api/orders')->assertOk()))->toBe([$this->mine->id, $this->teammate->id, $this->other->id]);
});

it('filters by status, owner, team, client, dates, overdue and search', function () {
    Payment::factory()->overdue()->create(['order_id' => $this->mine->id, 'amount_cents' => 1000]);
    Payment::factory()->create(['order_id' => $this->other->id, 'amount_cents' => 1000]);
    $this->actingAsRole(RoleName::Support);

    expect(orderIds($this->getJson('/api/orders?filter[status]=completed')->assertOk()))->toBe([$this->teammate->id])
        ->and(orderIds($this->getJson('/api/orders?filter[owner]='.$this->alpha->agent(1)->id)->assertOk()))->toBe([$this->teammate->id])
        ->and(orderIds($this->getJson('/api/orders?filter[team]='.$this->bravo->team->id)->assertOk()))->toBe([$this->other->id])
        ->and(orderIds($this->getJson('/api/orders?filter[client]='.$this->mine->client_id)->assertOk()))->toBe([$this->mine->id])
        ->and(orderIds($this->getJson('/api/orders?filter[ordered_from]=2026-09-01&filter[ordered_to]=2026-09-15')->assertOk()))->toBe([$this->mine->id])
        ->and(orderIds($this->getJson('/api/orders?filter[has_overdue]=true')->assertOk()))->toBe([$this->mine->id])
        ->and(orderIds($this->getJson('/api/orders?filter[search]='.$this->teammate->order_number)->assertOk()))->toBe([$this->teammate->id])
        ->and(orderIds($this->getJson('/api/orders?filter[search]='.urlencode((string) $this->other->client->discord_username))->assertOk()))->toBe([$this->other->id]);
});

it('sorts, paginates and rejects unknown query parameters with 400', function () {
    $this->actingAsRole(RoleName::Support);

    $totals = fn ($r) => collect($r->json('data'))->pluck('total.amount_cents')->all();
    expect($totals($this->getJson('/api/orders?sort=total_cents')))->toBe([50000, 70000, 100000])
        ->and($totals($this->getJson('/api/orders?sort=-total_cents')))->toBe([100000, 70000, 50000])
        ->and($totals($this->getJson('/api/orders')))->toBe([50000, 100000, 70000]); // default -ordered_on

    $this->getJson('/api/orders?page[size]=2&page[number]=2')->assertOk()->assertJsonPath('meta.total', 3)->assertJsonCount(1, 'data');
    $this->getJson('/api/orders?filter[bogus]=1')->assertStatus(400);
    $this->getJson('/api/orders?sort=bogus')->assertStatus(400);
    $this->getJson('/api/orders?include=bogus')->assertStatus(400);
});

it('derives amount paid, balance and overdue from payments and formats values', function () {
    Payment::factory()->paid()->create(['order_id' => $this->mine->id, 'sequence' => 1, 'amount_cents' => 25050]);
    Payment::factory()->overdue()->create(['order_id' => $this->mine->id, 'sequence' => 2, 'amount_cents' => 10000]);
    $this->actingAsUser($this->alpha->agent(0));

    $this->getJson('/api/orders?include=client,items,payments')
        ->assertOk()
        ->assertJsonPath('data.0.order_number', $this->mine->order_number)
        ->assertJsonPath('data.0.status.label', 'Pending Payment')
        ->assertJsonPath('data.0.type.value', 'fresh')
        ->assertJsonPath('data.0.total.formatted', '$1,000.00')
        ->assertJsonPath('data.0.amount_paid.amount_cents', 25050)
        ->assertJsonPath('data.0.balance.amount_cents', 74950)
        ->assertJsonPath('data.0.overdue_payments_count', 1)
        ->assertJsonPath('data.0.ordered_on', '2026-09-10')
        ->assertJsonPath('data.0.client.id', $this->mine->client_id)
        ->assertJsonCount(1, 'data.0.items')
        ->assertJsonCount(2, 'data.0.payments')
        ->assertJsonPath('data.0.items.0.line_total.amount_cents', 100000);
});
