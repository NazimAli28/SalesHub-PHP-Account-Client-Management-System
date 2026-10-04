<?php

use App\Enums\RoleName;
use App\Models\PlatformAccount;
use App\Models\Workstation;

beforeEach(function () {
    $this->alpha = $this->makeTeamWithMembers(2);
    $this->bravo = $this->makeTeamWithMembers(1);
});

it('lists workstations for every role with user and account counts', function (RoleName $role) {
    $this->actingAsRole($role);
    PlatformAccount::factory()->count(2)->create(['workstation_id' => $this->alpha->station(0)->id]);

    $response = $this->getJson('/api/workstations')->assertOk()->assertJsonCount(3, 'data');
    $row = collect($response->json('data'))->firstWhere('id', $this->alpha->station(0)->id);

    expect($row['users_count'])->toBe(1)
        ->and($row['platform_accounts_count'])->toBe(2)
        ->and($row['team']['id'])->toBe($this->alpha->team->id)
        ->and($row['team_id'])->toBe($this->alpha->team->id);
})->with(RoleName::cases());

it('requires authentication', function () {
    $this->getJson('/api/workstations')->assertUnauthorized();
});

it('filters, sorts, includes and paginates', function () {
    $this->actingAsRole(RoleName::Support);
    $off = Workstation::factory()->create(['team_id' => $this->bravo->team->id, 'code' => 'ZZ-9', 'label' => 'Window seat', 'is_active' => false]);

    expect($this->getJson('/api/workstations?filter[active]=false')->json('data.*.id'))->toBe([$off->id]);
    expect($this->getJson('/api/workstations?filter[team]='.$this->alpha->team->id)->json('data'))->toHaveCount(2);
    expect($this->getJson('/api/workstations?filter[search]=window')->json('data.*.id'))->toBe([$off->id]);
    expect($this->getJson('/api/workstations?filter[search]=ZZ-')->json('data.*.id'))->toBe([$off->id]);
    expect($this->getJson('/api/workstations?sort=-code')->json('data.0.code'))->toBe('ZZ-9');
    expect($this->getJson('/api/workstations?sort=code')->json('data.0.code'))->not->toBe('ZZ-9');
    $this->getJson('/api/workstations?page[size]=2&page[number]=2')->assertJsonPath('meta.total', 4)->assertJsonCount(2, 'data');
    $this->getJson('/api/workstations?filter[bogus]=1')->assertStatus(400);

    $row = $this->getJson('/api/workstations?include=users&filter[team]='.$this->alpha->team->id)->json('data.0');
    expect($row['users'])->toHaveCount(1)->and($row['users'][0])->toHaveKeys(['id', 'name', 'username']);
});

it('shows a workstation', function () {
    $this->actingAsUser($this->alpha->agent(0));

    $this->getJson('/api/workstations/'.$this->bravo->station(0)->id)
        ->assertOk()
        ->assertJsonPath('data.code', $this->bravo->station(0)->code)
        ->assertJsonPath('data.users_count', 1)
        ->assertJsonPath('data.users.0.id', $this->bravo->agent(0)->id);
    $this->getJson('/api/workstations/99999')->assertNotFound();
});

it('lets support and admin write, and nobody else', function () {
    foreach ([RoleName::TeamLead, RoleName::SalesExecutive] as $role) {
        $this->actingAsRole($role);
        $this->postJson('/api/workstations', ['code' => 'PC-9999', 'team_id' => $this->alpha->team->id])->assertForbidden();
        $this->patchJson('/api/workstations/'.$this->alpha->station(0)->id, ['label' => 'x'])->assertForbidden();
        $this->deleteJson('/api/workstations/'.$this->alpha->station(0)->id)->assertForbidden();
    }

    foreach ([RoleName::Support, RoleName::Admin] as $i => $role) {
        $this->actingAsRole($role);
        $this->postJson('/api/workstations', ['code' => "PC-90{$i}", 'team_id' => $this->alpha->team->id, 'label' => 'New seat'])
            ->assertCreated()
            ->assertJsonPath('data.code', "PC-90{$i}")
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.users_count', 0);
    }
});

it('validates workstation input', function (array $payload, string $field) {
    $this->actingAsRole(RoleName::Admin);

    $this->postJson('/api/workstations', [...['code' => 'PC-9001', 'team_id' => $this->alpha->team->id], ...$payload])
        ->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    'missing code' => [['code' => null], 'code'],
    'code too long' => [['code' => str_repeat('A', 21)], 'code'],
    'missing team' => [['team_id' => null], 'team_id'],
    'unknown team' => [['team_id' => 99999], 'team_id'],
    'bad active flag' => [['is_active' => 'maybe'], 'is_active'],
]);

it('rejects duplicate codes', function () {
    $this->actingAsRole(RoleName::Admin);

    $this->postJson('/api/workstations', ['code' => $this->alpha->station(0)->code, 'team_id' => $this->alpha->team->id])
        ->assertUnprocessable()->assertJsonValidationErrors('code');
});

it('updates a workstation, including deactivation', function () {
    $this->actingAsRole(RoleName::Support);
    $empty = Workstation::factory()->create(['team_id' => $this->alpha->team->id]);

    $this->patchJson("/api/workstations/{$empty->id}", ['label' => 'Corner', 'is_active' => false, 'team_id' => $this->bravo->team->id])
        ->assertOk()
        ->assertJsonPath('data.label', 'Corner')
        ->assertJsonPath('data.is_active', false)
        ->assertJsonPath('data.team.id', $this->bravo->team->id);

    $this->patchJson("/api/workstations/{$empty->id}", ['label' => null])->assertOk()->assertJsonPath('data.label', null);
    $this->patchJson("/api/workstations/{$empty->id}", ['code' => $this->alpha->station(0)->code])->assertUnprocessable();
});

it('keeps seated users on the workstation team', function () {
    $this->actingAsRole(RoleName::Support);

    $this->patchJson('/api/workstations/'.$this->alpha->station(0)->id, ['team_id' => $this->bravo->team->id])
        ->assertUnprocessable()->assertJsonValidationErrors('team_id');
});

it('deletes only unused workstations', function () {
    $this->actingAsRole(RoleName::Support);

    $this->deleteJson('/api/workstations/'.$this->alpha->station(0)->id)->assertUnprocessable()->assertJsonValidationErrors('workstation');

    $withAccount = Workstation::factory()->create(['team_id' => $this->alpha->team->id]);
    PlatformAccount::factory()->create(['workstation_id' => $withAccount->id]);
    $this->deleteJson("/api/workstations/{$withAccount->id}")->assertUnprocessable();

    $free = Workstation::factory()->create(['team_id' => $this->alpha->team->id]);
    $this->deleteJson("/api/workstations/{$free->id}")->assertNoContent();

    expect(Workstation::query()->find($free->id))->toBeNull()
        ->and(Workstation::withTrashed()->find($free->id))->not->toBeNull();
});
