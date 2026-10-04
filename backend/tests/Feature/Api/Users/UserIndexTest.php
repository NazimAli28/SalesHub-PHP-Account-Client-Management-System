<?php

use App\Enums\RoleName;
use App\Models\User;

beforeEach(function () {
    $this->alpha = $this->makeTeamWithMembers(2);
    $this->bravo = $this->makeTeamWithMembers(1);
});

function userIds($response): array
{
    return collect($response->json('data'))->pluck('id')->sort()->values()->all();
}

it('scopes the index to the own team for a team lead', function () {
    $this->actingAsUser($this->alpha->teamLead);

    expect(userIds($this->getJson('/api/users')->assertOk()))
        ->toBe(collect([$this->alpha->teamLead, ...$this->alpha->agents])->pluck('id')->sort()->values()->all());
});

it('shows every user to support and admin', function (RoleName $role) {
    $actor = $this->actingAsRole($role);

    expect(userIds($this->getJson('/api/users')->assertOk()))->toBe(User::query()->pluck('id')->sort()->values()->all())
        ->and(User::query()->whereKey($actor->id)->exists())->toBeTrue();
})->with([RoleName::Support, RoleName::Admin]);

it('denies the index to sales executives and guests', function () {
    $this->getJson('/api/users')->assertUnauthorized();

    $this->actingAsUser($this->alpha->agent(0));
    $this->getJson('/api/users')->assertForbidden();
});

it('never exposes password or token columns', function () {
    $this->actingAsRole(RoleName::Admin);

    $row = $this->getJson('/api/users')->assertOk()->json('data.0');

    expect($row)->not->toHaveKeys(['password', 'remember_token', 'last_login_ip'])
        ->and($row)->toHaveKeys(['id', 'name', 'username', 'email', 'is_active', 'roles', 'team', 'workstation', 'team_id', 'created_at']);
});

it('filters by role, team, active flag and search', function () {
    $admin = $this->actingAsRole(RoleName::Support);
    $inactive = $this->makeStaff(RoleName::SalesExecutive, $this->alpha->team, null, ['is_active' => false, 'name' => 'Zed Findme']);

    expect(userIds($this->getJson('/api/users?filter[role]=team_lead')->assertOk()))
        ->toBe(collect([$this->alpha->teamLead, $this->bravo->teamLead])->pluck('id')->sort()->values()->all());

    expect(userIds($this->getJson('/api/users?filter[role]=team_lead,support')->assertOk()))
        ->toContain($admin->id, $this->alpha->teamLead->id);

    expect(userIds($this->getJson('/api/users?filter[team]='.$this->bravo->team->id)->assertOk()))
        ->toBe(collect([$this->bravo->teamLead, $this->bravo->agent(0)])->pluck('id')->sort()->values()->all());

    expect(userIds($this->getJson('/api/users?filter[active]=false')->assertOk()))->toBe([$inactive->id]);
    expect(userIds($this->getJson('/api/users?filter[active]=true')->assertOk()))->not->toContain($inactive->id);

    expect(userIds($this->getJson('/api/users?filter[search]=findme')->assertOk()))->toBe([$inactive->id]);
    expect(userIds($this->getJson('/api/users?filter[search]='.$this->alpha->agent(1)->username)->assertOk()))
        ->toBe([$this->alpha->agent(1)->id]);
    expect(userIds($this->getJson('/api/users?filter[search]='.$this->bravo->teamLead->email)->assertOk()))
        ->toBe([$this->bravo->teamLead->id]);
});

it('filters by created date range', function () {
    $this->actingAsRole(RoleName::Admin);
    $old = $this->makeUser(RoleName::SalesExecutive, ['created_at' => '2025-01-15 10:00:00']);

    expect(userIds($this->getJson('/api/users?filter[created_from]=2025-01-01&filter[created_to]=2025-01-31')->assertOk()))
        ->toBe([$old->id]);

    $this->getJson('/api/users?filter[created_from]=nope')->assertUnprocessable();
});

it('sorts ascending and descending', function () {
    $this->actingAsRole(RoleName::Admin);
    $this->makeUser(RoleName::SalesExecutive, ['name' => 'Aaa First']);
    $this->makeUser(RoleName::SalesExecutive, ['name' => 'Zzz Last']);

    expect($this->getJson('/api/users?sort=name')->json('data.0.name'))->toBe('Aaa First')
        ->and($this->getJson('/api/users?sort=-name')->json('data.0.name'))->toBe('Zzz Last');
});

it('rejects unknown filters and sorts', function () {
    $this->actingAsRole(RoleName::Admin);

    $this->getJson('/api/users?filter[password]=x')->assertStatus(400);
    $this->getJson('/api/users?sort=password')->assertStatus(400);
});

it('paginates and keeps the query string in the links', function () {
    $this->actingAsRole(RoleName::Admin);
    User::factory()->count(12)->salesExecutive()->create();

    $response = $this->getJson('/api/users?page[size]=5&page[number]=2&filter[role]=sales_executive')
        ->assertOk()
        ->assertJsonPath('meta.per_page', 5)
        ->assertJsonPath('meta.current_page', 2)
        ->assertJsonCount(5, 'data');

    expect(urldecode((string) $response->json('links.next')))->toContain('page[number]=3')->toContain('filter[role]=sales_executive');
});
