<?php

use App\Enums\ClientStatus;
use App\Enums\OrderStatus;
use App\Enums\RoleName;
use App\Models\Client;
use App\Models\Lead;
use App\Models\Order;
use App\Models\Payment;

beforeEach(function () {
    $this->alpha = $this->makeTeamWithMembers(2);
    $this->bravo = $this->makeTeamWithMembers(1);

    $this->mine = Client::factory()->create(['owner_id' => $this->alpha->agent(0)->id, 'name' => 'Mia Stream', 'email' => 'mia@example.com', 'discord_username' => 'miastream']);
    $this->teammate = Client::factory()->create(['owner_id' => $this->alpha->agent(1)->id, 'name' => 'Theo Twitch']);
    $this->other = Client::factory()->create(['owner_id' => $this->bravo->agent(0)->id, 'name' => 'Olga Other']);
});

function clientIds($response): array
{
    return collect($response->json('data'))->pluck('id')->sort()->values()->all();
}

it('scopes the index to own clients for a sales executive', function () {
    $this->actingAsUser($this->alpha->agent(0));

    expect(clientIds($this->getJson('/api/clients')->assertOk()))->toBe([$this->mine->id]);
});

it('also shows clients with a lead or order owned by the sales executive', function () {
    Lead::factory()->create(['client_id' => $this->teammate->id, 'owner_id' => $this->alpha->agent(0)->id]);
    $this->actingAsUser($this->alpha->agent(0));

    expect(clientIds($this->getJson('/api/clients')->assertOk()))->toBe([$this->mine->id, $this->teammate->id]);
});

it('scopes the index to the team for a team lead', function () {
    $this->actingAsUser($this->alpha->teamLead);

    expect(clientIds($this->getJson('/api/clients')->assertOk()))->toBe([$this->mine->id, $this->teammate->id]);
});

it('shows every client to support and admin', function (RoleName $role) {
    $this->actingAsRole($role);

    expect(clientIds($this->getJson('/api/clients')->assertOk()))->toBe([$this->mine->id, $this->teammate->id, $this->other->id]);
})->with([RoleName::Support, RoleName::Admin]);

it('filters by owner, status, created range and has_open_orders', function () {
    $this->actingAsRole(RoleName::Support);
    $this->teammate->update(['status' => ClientStatus::Dormant]);
    Client::query()->whereKey($this->other->id)->update(['created_at' => '2025-01-15 10:00:00']);
    Order::factory()->create(['client_id' => $this->mine->id, 'owner_id' => $this->alpha->agent(0)->id, 'status' => OrderStatus::InProgress]);
    Order::factory()->create(['client_id' => $this->teammate->id, 'owner_id' => $this->alpha->agent(1)->id, 'status' => OrderStatus::Completed]);

    expect(clientIds($this->getJson('/api/clients?filter[owner]='.$this->alpha->agent(1)->id)->assertOk()))->toBe([$this->teammate->id])
        ->and(clientIds($this->getJson('/api/clients?filter[status]=dormant')->assertOk()))->toBe([$this->teammate->id])
        ->and(clientIds($this->getJson('/api/clients?filter[created_to]=2025-02-01')->assertOk()))->toBe([$this->other->id])
        ->and(clientIds($this->getJson('/api/clients?filter[created_from]=2025-02-01')->assertOk()))->toBe([$this->mine->id, $this->teammate->id])
        ->and(clientIds($this->getJson('/api/clients?filter[has_open_orders]=true')->assertOk()))->toBe([$this->mine->id])
        ->and(clientIds($this->getJson('/api/clients?filter[has_open_orders]=false')->assertOk()))->toBe([$this->teammate->id, $this->other->id]);
});

it('searches name, email and discord username', function () {
    $this->actingAsRole(RoleName::Support);

    expect(clientIds($this->getJson('/api/clients?filter[search]=Mia')->assertOk()))->toBe([$this->mine->id])
        ->and(clientIds($this->getJson('/api/clients?filter[search]=mia@example')->assertOk()))->toBe([$this->mine->id])
        ->and(clientIds($this->getJson('/api/clients?filter[search]=miastream')->assertOk()))->toBe([$this->mine->id]);
});

it('sorts and rejects unknown filters, sorts and includes with 400', function () {
    $this->actingAsRole(RoleName::Support);

    $names = fn ($r) => collect($r->json('data'))->pluck('name')->all();
    expect($names($this->getJson('/api/clients?sort=name')))->toBe(['Mia Stream', 'Olga Other', 'Theo Twitch'])
        ->and($names($this->getJson('/api/clients?sort=-name')))->toBe(['Theo Twitch', 'Olga Other', 'Mia Stream']);

    $this->getJson('/api/clients?filter[bogus]=1')->assertStatus(400);
    $this->getJson('/api/clients?sort=bogus')->assertStatus(400);
    $this->getJson('/api/clients?include=bogus')->assertStatus(400);
    $this->getJson('/api/clients?filter[created_from]=yesterday')->assertUnprocessable();
});

it('formats values, includes relations and shows the lifetime value', function () {
    $order = Order::factory()->create(['client_id' => $this->mine->id, 'owner_id' => $this->alpha->agent(0)->id]);
    Payment::factory()->paid()->create(['order_id' => $order->id, 'amount_cents' => 12345]);
    Payment::factory()->create(['order_id' => $order->id, 'sequence' => 2, 'amount_cents' => 99900]);
    $this->actingAsUser($this->alpha->agent(0));

    $this->getJson('/api/clients?include=owner&page[size]=10')
        ->assertOk()
        ->assertJsonPath('data.0.status.value', 'active')
        ->assertJsonPath('data.0.status.label', 'Active')
        ->assertJsonPath('data.0.lifetime_value.amount_cents', 12345)
        ->assertJsonPath('data.0.lifetime_value.formatted', '$123.45')
        ->assertJsonPath('data.0.owner.id', $this->alpha->agent(0)->id)
        ->assertJsonPath('data.0.pending_change', null)
        ->assertJsonPath('meta.per_page', 10);
});
