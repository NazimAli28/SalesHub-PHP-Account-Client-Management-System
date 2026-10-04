<?php

use App\Enums\LeadStage;
use App\Enums\RoleName;
use App\Models\Client;
use App\Models\Lead;
use App\Models\PlatformAccount;
use App\Models\Service;

beforeEach(function () {
    $this->alpha = $this->makeTeamWithMembers(2);
    $this->bravo = $this->makeTeamWithMembers(1);

    $this->mine = Lead::factory()->create(['owner_id' => $this->alpha->agent(0)->id]);
    $this->teammate = Lead::factory()->create(['owner_id' => $this->alpha->agent(1)->id]);
    $this->other = Lead::factory()->create(['owner_id' => $this->bravo->agent(0)->id]);
});

function leadIds($response): array
{
    return collect($response->json('data'))->pluck('id')->sort()->values()->all();
}

it('scopes the index to own leads for a sales executive', function () {
    $this->actingAsUser($this->alpha->agent(0));

    expect(leadIds($this->getJson('/api/leads')->assertOk()))->toBe([$this->mine->id]);
});

it('scopes the index to the team for a team lead', function () {
    $this->actingAsUser($this->alpha->teamLead);

    expect(leadIds($this->getJson('/api/leads')->assertOk()))->toBe([$this->mine->id, $this->teammate->id]);
});

it('shows every lead to support and admin', function (RoleName $role) {
    $this->actingAsRole($role);

    expect(leadIds($this->getJson('/api/leads')->assertOk()))
        ->toBe([$this->mine->id, $this->teammate->id, $this->other->id]);
})->with([RoleName::Support, RoleName::Admin]);

it('paginates with page[size] and page[number] and keeps the query string in links', function () {
    Lead::factory()->count(12)->create(['owner_id' => $this->alpha->agent(0)->id]);
    $this->actingAsRole(RoleName::Support);

    $response = $this->getJson('/api/leads?page[size]=5&page[number]=2&filter[owner]='.$this->alpha->agent(0)->id)
        ->assertOk()
        ->assertJsonPath('meta.per_page', 5)
        ->assertJsonPath('meta.current_page', 2)
        ->assertJsonPath('meta.total', 13)
        ->assertJsonPath('meta.last_page', 3)
        ->assertJsonCount(5, 'data');

    $next = urldecode((string) $response->json('links.next'));
    expect($next)->toContain('page[number]=3')
        ->toContain('page[size]=5')
        ->toContain('filter[owner]='.$this->alpha->agent(0)->id);
});

it('defaults to 25 per page and caps page[size] at 100', function () {
    $this->actingAsRole(RoleName::Support);

    $this->getJson('/api/leads')->assertJsonPath('meta.per_page', 25);
    $this->getJson('/api/leads?page[size]=500')->assertJsonPath('meta.per_page', 100);
});

it('filters by stage, owner, client, platform account, date range and search', function () {
    $this->actingAsRole(RoleName::Support);

    $client = Client::factory()->create(['discord_username' => 'pixelqueen77']);
    $account = PlatformAccount::factory()->assigned($this->alpha->station(0))->create();
    $target = Lead::factory()->quoted()->create([
        'owner_id' => $this->alpha->agent(1)->id,
        'client_id' => $client->id,
        'platform_account_id' => $account->id,
        'contacted_on' => '2025-01-15',
        'last_message' => 'Sent the overlay quote',
    ]);

    $only = fn (string $query) => leadIds($this->getJson('/api/leads?'.$query)->assertOk());

    expect($only('filter[stage]=quoted'))->toBe([$target->id])
        ->and($only('filter[stage]=quoted,new'))->toContain($target->id)
        ->and($only('filter[owner]='.$this->alpha->agent(1)->id))->toBe([$this->teammate->id, $target->id])
        ->and($only('filter[client]='.$client->id))->toBe([$target->id])
        ->and($only('filter[platform_account]='.$account->id))->toBe([$target->id])
        ->and($only('filter[contacted_from]=2025-01-15&filter[contacted_to]=2025-01-15'))->toBe([$target->id])
        ->and($only('filter[search]=pixelqueen'))->toBe([$target->id])
        ->and($only('filter[search]=overlay quote'))->toBe([$target->id]);
});

it('rejects malformed dates and unknown filters', function () {
    $this->actingAsRole(RoleName::Support);

    $this->getJson('/api/leads?filter[contacted_from]=15-08-2026')->assertUnprocessable();
    $this->getJson('/api/leads?filter[password]=x')->assertBadRequest();
});

it('sorts by whitelisted fields only', function () {
    $this->actingAsRole(RoleName::Support);
    Lead::query()->whereKey($this->mine->id)->update(['estimated_value_cents' => 100]);
    Lead::query()->whereKey($this->teammate->id)->update(['estimated_value_cents' => 300]);
    Lead::query()->whereKey($this->other->id)->update(['estimated_value_cents' => 200]);

    $ids = fn (string $sort) => collect($this->getJson('/api/leads?sort='.$sort)->assertOk()->json('data'))->pluck('id')->all();

    expect($ids('estimated_value_cents'))->toBe([$this->mine->id, $this->other->id, $this->teammate->id])
        ->and($ids('-estimated_value_cents'))->toBe([$this->teammate->id, $this->other->id, $this->mine->id]);

    $this->getJson('/api/leads?sort=owner_id')->assertBadRequest();
});

it('includes whitelisted relations and formats enums, money and dates', function () {
    $service = Service::factory()->create();
    $this->mine->services()->attach($service);
    $this->mine->update(['estimated_value_cents' => 123450, 'contacted_on' => '2026-09-01']);
    $this->actingAsUser($this->alpha->agent(0));

    $this->getJson('/api/leads?include=client,owner,services')
        ->assertOk()
        ->assertJsonPath('data.0.stage', ['value' => 'new', 'label' => 'New'])
        ->assertJsonPath('data.0.estimated_value', ['amount_cents' => 123450, 'currency' => 'USD', 'formatted' => '$1,234.50'])
        ->assertJsonPath('data.0.contacted_on', '2026-09-01')
        ->assertJsonPath('data.0.owner.id', $this->alpha->agent(0)->id)
        ->assertJsonPath('data.0.client.id', $this->mine->client_id)
        ->assertJsonPath('data.0.services.0.id', $service->id)
        ->assertJsonPath('data.0.pending_change', null)
        ->assertJsonMissingPath('data.0.platform_account');

    expect($this->getJson('/api/leads')->json('data.0.stage_changed_at'))->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/');

    $this->getJson('/api/leads?include=order')->assertBadRequest();
});

it('requires authentication', function () {
    $this->getJson('/api/leads')->assertUnauthorized();
});

it('keeps stage values in pipeline order for the kanban', function () {
    expect(array_map(fn (LeadStage $s) => $s->value, LeadStage::ordered()))
        ->toBe(['new', 'engaged', 'portfolio_shared', 'quoted', 'payment_pending', 'won', 'lost']);
});
