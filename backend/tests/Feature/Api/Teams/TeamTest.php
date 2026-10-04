<?php

use App\Enums\RoleName;
use App\Models\Team;

beforeEach(function () {
    $this->alpha = $this->makeTeamWithMembers(2);
    $this->bravo = $this->makeTeamWithMembers(1);
});

it('lists teams for every role with member and workstation counts', function (RoleName $role) {
    $this->actingAsRole($role);

    $response = $this->getJson('/api/teams?sort=id')->assertOk()->assertJsonCount(2, 'data');

    $first = collect($response->json('data'))->firstWhere('id', $this->alpha->team->id);
    expect($first['members_count'])->toBe(3)
        ->and($first['workstations_count'])->toBe(2)
        ->and($first['team_lead']['id'])->toBe($this->alpha->teamLead->id)
        ->and($first['shift'])->toHaveKeys(['value', 'label']);
})->with(RoleName::cases());

it('requires authentication', function () {
    $this->getJson('/api/teams')->assertUnauthorized();
});

it('filters, sorts and paginates', function () {
    $this->actingAsRole(RoleName::Admin);
    $night = Team::factory()->create(['name' => 'Zulu Unit', 'shift' => 'night', 'floor' => 9]);

    expect(collect($this->getJson('/api/teams?filter[shift]=night')->json('data'))->pluck('id')->all())
        ->toContain($night->id);
    expect($this->getJson('/api/teams?filter[floor]=9')->json('data.0.id'))->toBe($night->id);
    expect($this->getJson('/api/teams?filter[search]=zulu')->assertJsonCount(1, 'data')->json('data.0.id'))->toBe($night->id);
    expect($this->getJson('/api/teams?filter[team_lead]='.$this->bravo->teamLead->id)->json('data.0.id'))->toBe($this->bravo->team->id);
    expect($this->getJson('/api/teams?sort=-name')->json('data.0.name'))->toBe('Zulu Unit');
    $this->getJson('/api/teams?page[size]=2&page[number]=2')->assertOk()->assertJsonPath('meta.total', 3)->assertJsonCount(1, 'data');
    $this->getJson('/api/teams?filter[nope]=1')->assertStatus(400);
    $this->getJson('/api/teams?include=secrets')->assertStatus(400);
});

it('includes members and workstations on request', function () {
    $this->actingAsUser($this->alpha->agent(0));

    $row = $this->getJson('/api/teams?include=members,workstations&filter[team_lead]='.$this->alpha->teamLead->id)
        ->assertOk()->json('data.0');

    expect($row['members'])->toHaveCount(3)
        ->and($row['members'][0])->toHaveKeys(['id', 'name', 'username'])->not->toHaveKey('email')
        ->and($row['workstations'])->toHaveCount(2);
});

it('shows a team with members', function () {
    $this->actingAsUser($this->alpha->agent(0));

    $this->getJson('/api/teams/'.$this->bravo->team->id)
        ->assertOk()
        ->assertJsonPath('data.id', $this->bravo->team->id)
        ->assertJsonPath('data.members_count', 2)
        ->assertJsonCount(2, 'data.members');
    $this->getJson('/api/teams/99999')->assertNotFound();
});

it('lets only admin write teams', function (RoleName $role) {
    $this->actingAsRole($role);

    $this->postJson('/api/teams', ['name' => 'Unit 9 Echo', 'floor' => 2, 'shift' => 'morning'])->assertForbidden();
    $this->patchJson('/api/teams/'.$this->alpha->team->id, ['name' => 'Renamed'])->assertForbidden();
    $this->deleteJson('/api/teams/'.$this->bravo->team->id)->assertForbidden();
})->with([RoleName::Support, RoleName::TeamLead, RoleName::SalesExecutive]);

it('creates a team and seats its lead', function () {
    $this->actingAsRole(RoleName::Admin);
    $lead = $this->makeUser(RoleName::TeamLead);

    $this->postJson('/api/teams', ['name' => 'Unit 9 Echo', 'floor' => 2, 'shift' => 'morning', 'team_lead_id' => $lead->id])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Unit 9 Echo')
        ->assertJsonPath('data.shift.value', 'morning')
        ->assertJsonPath('data.team_lead.id', $lead->id);

    expect($lead->refresh()->team_id)->toBe(Team::query()->where('name', 'Unit 9 Echo')->value('id'));
});

it('validates team input', function (array $payload, string $field) {
    $this->actingAsRole(RoleName::Admin);

    $this->postJson('/api/teams', [...['name' => 'Unit 9 Echo', 'floor' => 2, 'shift' => 'morning'], ...$payload])
        ->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    'missing name' => [['name' => null], 'name'],
    'bad shift' => [['shift' => 'noon'], 'shift'],
    'bad floor' => [['floor' => 'high'], 'floor'],
    'lead not found' => [['team_lead_id' => 99999], 'team_lead_id'],
]);

it('rejects duplicate team names', function () {
    $this->actingAsRole(RoleName::Admin);

    $this->postJson('/api/teams', ['name' => $this->alpha->team->name, 'floor' => 1, 'shift' => 'night'])
        ->assertUnprocessable()->assertJsonValidationErrors('name');
});

it('only accepts an active team lead on the team as lead', function () {
    $this->actingAsRole(RoleName::Admin);
    $team = $this->alpha->team;

    // Not a team lead.
    $this->patchJson("/api/teams/{$team->id}", ['team_lead_id' => $this->alpha->agent(0)->id])
        ->assertUnprocessable()->assertJsonValidationErrors('team_lead_id');
    // Team lead of another team.
    $this->patchJson("/api/teams/{$team->id}", ['team_lead_id' => $this->bravo->teamLead->id])
        ->assertUnprocessable()->assertJsonValidationErrors('team_lead_id');
    // Inactive team lead on this team.
    $inactive = $this->makeStaff(RoleName::TeamLead, $team, null, ['is_active' => false]);
    $this->patchJson("/api/teams/{$team->id}", ['team_lead_id' => $inactive->id])
        ->assertUnprocessable()->assertJsonValidationErrors('team_lead_id');

    // A valid second lead on this team.
    $next = $this->makeStaff(RoleName::TeamLead, $team);
    $this->patchJson("/api/teams/{$team->id}", ['team_lead_id' => $next->id])
        ->assertOk()->assertJsonPath('data.team_lead_id', $next->id);
});

it('does not let one user lead two teams', function () {
    $this->actingAsRole(RoleName::Admin);
    $team = Team::factory()->create();
    $this->alpha->teamLead->update(['team_id' => $team->id]);

    $this->patchJson("/api/teams/{$team->id}", ['team_lead_id' => $this->alpha->teamLead->id])
        ->assertUnprocessable()->assertJsonValidationErrors('team_lead_id');
});

it('updates and clears the lead', function () {
    $this->actingAsRole(RoleName::Admin);

    $this->putJson('/api/teams/'.$this->alpha->team->id, ['name' => 'Unit 1 Renamed', 'floor' => 4, 'team_lead_id' => null])
        ->assertOk()
        ->assertJsonPath('data.name', 'Unit 1 Renamed')
        ->assertJsonPath('data.floor', 4)
        ->assertJsonPath('data.team_lead_id', null)
        ->assertJsonPath('data.team_lead', null);

    $this->patchJson('/api/teams/'.$this->alpha->team->id, ['name' => $this->bravo->team->name])->assertUnprocessable();
});

it('deletes only empty teams', function () {
    $this->actingAsRole(RoleName::Admin);

    $this->deleteJson('/api/teams/'.$this->alpha->team->id)->assertUnprocessable()->assertJsonValidationErrors('team');

    $empty = Team::factory()->create();
    $this->deleteJson("/api/teams/{$empty->id}")->assertNoContent();

    expect(Team::query()->find($empty->id))->toBeNull()
        ->and(Team::withTrashed()->find($empty->id))->not->toBeNull();
});
