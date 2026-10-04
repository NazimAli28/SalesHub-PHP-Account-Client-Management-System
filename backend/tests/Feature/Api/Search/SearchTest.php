<?php

use App\Enums\RoleName;
use App\Models\Client;
use App\Models\Lead;
use App\Models\Order;
use App\Models\PlatformAccount;
use App\Models\User;

beforeEach(function () {
    $this->alpha = $this->makeTeamWithMembers(2);
    $this->bravo = $this->makeTeamWithMembers(1);

    $this->mine = Client::factory()->create(['owner_id' => $this->alpha->agent(0)->id, 'name' => 'Zelda Streamer', 'discord_username' => 'zeldastream']);
    $this->other = Client::factory()->create(['owner_id' => $this->bravo->agent(0)->id, 'name' => 'Zora Other', 'discord_username' => 'zoraother']);
});

function searchGroupKeys($response): array
{
    return collect($response->json('data'))->pluck('key')->all();
}

function searchHitIds($response, string $group): array
{
    $hits = collect($response->json('data'))->firstWhere('key', $group)['hits'] ?? [];

    return collect($hits)->pluck('id')->sort()->values()->all();
}

it('requires authentication and a query of 2 to 100 characters', function () {
    $this->getJson('/api/search?q=ze')->assertUnauthorized();

    $this->actingAsRole(RoleName::Admin);
    $this->getJson('/api/search')->assertUnprocessable();
    $this->getJson('/api/search?q=z')->assertUnprocessable();
    $this->getJson('/api/search?q='.str_repeat('a', 101))->assertUnprocessable();
    $this->getJson('/api/search?q=ze')->assertOk();
});

it('finds clients, leads and orders by name or handle with SPA urls', function () {
    $lead = Lead::factory()->create(['client_id' => $this->mine->id, 'owner_id' => $this->alpha->agent(0)->id]);
    $order = Order::factory()->create(['client_id' => $this->mine->id, 'owner_id' => $this->alpha->agent(0)->id]);
    $this->actingAsRole(RoleName::Support);

    $response = $this->getJson('/api/search?q=zelda')->assertOk();

    expect(searchGroupKeys($response))->toBe(['clients', 'leads', 'orders', 'platform-accounts'])
        ->and(searchHitIds($response, 'clients'))->toBe([$this->mine->id])
        ->and(searchHitIds($response, 'leads'))->toBe([$lead->id])
        ->and(searchHitIds($response, 'orders'))->toBe([$order->id])
        ->and($response->json('data.0.hits.0'))->toMatchArray(['type' => 'client', 'title' => 'Zelda Streamer', 'url' => '/clients/'.$this->mine->id])
        ->and($response->json('data.2.hits.0.url'))->toBe('/orders/'.$order->id);

    $byNumber = $this->getJson('/api/search?q='.$order->order_number)->assertOk();
    expect(searchHitIds($byNumber, 'orders'))->toBe([$order->id]);
});

it('limits each group to five hits', function () {
    Client::factory()->count(7)->create(['owner_id' => $this->alpha->agent(0)->id, 'name' => 'Bulk Client']);
    $this->actingAsRole(RoleName::Admin);

    expect($this->getJson('/api/search?q=bulk client')->json('data.0.hits'))->toHaveCount(5);
});

it('treats LIKE wildcards in the query literally', function () {
    $this->actingAsRole(RoleName::Admin);

    expect($this->getJson('/api/search?q=%25%25')->json('data.0.hits'))->toBe([])
        ->and($this->getJson('/api/search?q=z_')->json('data.0.hits'))->toBe([]);
});

it('scopes hits to what the role may see', function () {
    $teammate = Client::factory()->create(['owner_id' => $this->alpha->agent(1)->id, 'name' => 'Zack Teammate']);

    $this->actingAsUser($this->alpha->agent(0));
    expect(searchHitIds($this->getJson('/api/search?q=ze')->assertOk(), 'clients'))->toBe([$this->mine->id])
        ->and(searchHitIds($this->getJson('/api/search?q=za')->assertOk(), 'clients'))->toBe([]);

    $this->actingAsUser($this->alpha->teamLead);
    expect(searchHitIds($this->getJson('/api/search?q=za')->assertOk(), 'clients'))->toBe([$teammate->id])
        ->and(searchHitIds($this->getJson('/api/search?q=zo')->assertOk(), 'clients'))->toBe([]);

    $this->actingAsRole(RoleName::Admin);
    expect(searchHitIds($this->getJson('/api/search?q=zo')->assertOk(), 'clients'))->toBe([$this->other->id]);
});

it('omits groups the user may not view', function () {
    $this->actingAsUser($this->alpha->agent(0));
    $keys = searchGroupKeys($this->getJson('/api/search?q=ze')->assertOk());

    expect($keys)->toContain('clients', 'leads', 'orders');

    $this->actingAsUser(User::factory()->create());
    $this->getJson('/api/search?q=ze')->assertOk()->assertExactJson(['data' => []]);
});

it('finds platform accounts by identifier only and never exposes credentials', function () {
    $account = PlatformAccount::factory()->assigned($this->alpha->station(0))->create([
        'email' => 'searchable.account@example.com',
        'discord_username' => 'searchablediscord',
        'email_password' => 'S3cret-pass-word',
        'discord_password' => 'D1scord-pass-word',
        'recovery_phone' => '+15550001234',
    ]);

    $this->actingAsRole(RoleName::Support);
    $response = $this->getJson('/api/search?q=searchable')->assertOk();

    expect(searchHitIds($response, 'platform-accounts'))->toBe([$account->id]);
    $json = $response->getContent();
    expect($json)->not->toContain('S3cret-pass-word')->not->toContain('D1scord-pass-word')->not->toContain('15550001234');

    // Credentials are not searchable either.
    $this->getJson('/api/search?q=S3cret')->assertOk();
    expect(searchHitIds($this->getJson('/api/search?q=S3cret'), 'platform-accounts'))->toBe([]);

    // A sales executive on another team does not see the account.
    $this->actingAsUser($this->bravo->agent(0));
    expect(searchHitIds($this->getJson('/api/search?q=searchable')->assertOk(), 'platform-accounts'))->toBe([]);
});

it('throttles to 60 requests per minute', function () {
    $this->actingAsRole(RoleName::Admin);

    foreach (range(1, 60) as $_) {
        $this->getJson('/api/search?q=ze')->assertOk();
    }

    $this->getJson('/api/search?q=ze')->assertStatus(429);
});
