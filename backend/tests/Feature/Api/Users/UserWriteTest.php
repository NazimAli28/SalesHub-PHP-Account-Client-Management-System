<?php

use App\Enums\RoleName;
use App\Models\Team;
use App\Models\User;
use Spatie\Activitylog\Models\Activity;

const NEW_PASSWORD = 'Str0ng!Passw0rd';

beforeEach(function () {
    $this->alpha = $this->makeTeamWithMembers(2);
    $this->bravo = $this->makeTeamWithMembers(1);
});

function newUserPayload(array $overrides = []): array
{
    return [
        'name' => 'Nova Tester',
        'username' => 'Nova.Tester',
        'email' => 'Nova.Tester@example.com',
        'password' => NEW_PASSWORD,
        'role' => 'sales_executive',
        ...$overrides,
    ];
}

it('creates a user with one role, lowercased identifiers and a hashed password', function () {
    $this->actingAsRole(RoleName::Support);
    $station = $this->alpha->station(0);

    $response = $this->postJson('/api/users', newUserPayload(['team_id' => $this->alpha->team->id, 'workstation_id' => $station->id]))
        ->assertCreated()
        ->assertJsonPath('data.username', 'nova.tester')
        ->assertJsonPath('data.email', 'nova.tester@example.com')
        ->assertJsonPath('data.roles', ['sales_executive'])
        ->assertJsonPath('data.team_id', $this->alpha->team->id)
        ->assertJsonPath('data.workstation.code', $station->code)
        ->assertJsonPath('data.is_active', true);

    $user = User::query()->findOrFail($response->json('data.id'));
    expect(Hash::check(NEW_PASSWORD, $user->password))->toBeTrue()
        ->and($response->json('data'))->not->toHaveKey('password');
});

it('lets admin create support and admin users but not support', function () {
    $this->actingAsRole(RoleName::Support);
    $this->postJson('/api/users', newUserPayload(['role' => 'admin']))->assertForbidden();
    $this->postJson('/api/users', newUserPayload(['role' => 'support']))->assertForbidden();
    $this->postJson('/api/users', newUserPayload(['role' => 'team_lead']))->assertCreated();

    $this->actingAsRole(RoleName::Admin);
    $this->postJson('/api/users', newUserPayload(['username' => 'second.admin', 'email' => 'second@example.com', 'role' => 'admin']))->assertCreated();
    $this->postJson('/api/users', newUserPayload(['username' => 'a.support', 'email' => 'support2@example.com', 'role' => 'support']))->assertCreated();
});

it('denies create to team leads and sales executives', function () {
    $this->actingAsUser($this->alpha->teamLead);
    $this->postJson('/api/users', newUserPayload())->assertForbidden();

    $this->actingAsUser($this->alpha->agent(0));
    $this->postJson('/api/users', newUserPayload())->assertForbidden();
});

it('validates user input', function (array $overrides, string $field) {
    $this->actingAsRole(RoleName::Admin);

    $this->postJson('/api/users', newUserPayload($overrides))->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    'missing name' => [['name' => ''], 'name'],
    'bad username chars' => [['username' => 'no spaces!'], 'username'],
    'short username' => [['username' => 'ab'], 'username'],
    'bad email' => [['email' => 'not-an-email'], 'email'],
    'weak password' => [['password' => 'password'], 'password'],
    'no symbol password' => [['password' => 'Abcdefghij12'], 'password'],
    'unknown role' => [['role' => 'owner'], 'role'],
    'missing role' => [['role' => null], 'role'],
    'unknown team' => [['team_id' => 99999], 'team_id'],
    'unknown workstation' => [['workstation_id' => 99999], 'workstation_id'],
]);

it('enforces unique usernames and emails case-insensitively', function () {
    $this->actingAsRole(RoleName::Admin);
    $existing = $this->alpha->agent(0);

    $this->postJson('/api/users', newUserPayload(['username' => strtoupper($existing->username)]))
        ->assertUnprocessable()->assertJsonValidationErrors('username');
    $this->postJson('/api/users', newUserPayload(['email' => strtoupper($existing->email)]))
        ->assertUnprocessable()->assertJsonValidationErrors('email');
});

it('requires the workstation to belong to the chosen team', function () {
    $this->actingAsRole(RoleName::Admin);

    $this->postJson('/api/users', newUserPayload(['team_id' => $this->alpha->team->id, 'workstation_id' => $this->bravo->station(0)->id]))
        ->assertUnprocessable()->assertJsonValidationErrors('workstation_id');
    $this->postJson('/api/users', newUserPayload(['workstation_id' => $this->alpha->station(0)->id]))
        ->assertUnprocessable()->assertJsonValidationErrors('workstation_id');
});

it('shows a user in scope and denies out-of-scope users', function () {
    $this->actingAsUser($this->alpha->teamLead);

    $this->getJson('/api/users/'.$this->alpha->agent(0)->id)->assertOk()->assertJsonPath('data.id', $this->alpha->agent(0)->id);
    $this->getJson('/api/users/'.$this->bravo->agent(0)->id)->assertForbidden();
    $this->getJson('/api/users/999999')->assertNotFound();
});

it('lets a sales executive see only their own record', function () {
    $this->actingAsUser($this->alpha->agent(0));

    $this->getJson('/api/users/'.$this->alpha->agent(0)->id)->assertOk();
    $this->getJson('/api/users/'.$this->alpha->agent(1)->id)->assertForbidden();
});

it('updates profile, team and workstation, and clears a stale workstation on team change', function () {
    $this->actingAsRole(RoleName::Support);
    $agent = $this->alpha->agent(0);

    $this->patchJson("/api/users/{$agent->id}", ['name' => 'Renamed Agent'])->assertOk()->assertJsonPath('data.name', 'Renamed Agent');

    $this->putJson("/api/users/{$agent->id}", ['team_id' => $this->bravo->team->id])
        ->assertOk()
        ->assertJsonPath('data.team_id', $this->bravo->team->id)
        ->assertJsonPath('data.workstation_id', null);

    $this->patchJson("/api/users/{$agent->id}", ['workstation_id' => $this->bravo->station(0)->id])
        ->assertOk()->assertJsonPath('data.workstation.code', $this->bravo->station(0)->code);
});

it('keeps the password when it is not sent and changes it when it is', function () {
    $this->actingAsRole(RoleName::Support);
    $agent = $this->alpha->agent(0);
    $hash = $agent->password;

    $this->patchJson("/api/users/{$agent->id}", ['name' => 'Same Pass'])->assertOk();
    expect($agent->refresh()->password)->toBe($hash);

    $this->patchJson("/api/users/{$agent->id}", ['password' => 'weak'])->assertUnprocessable();
    $this->patchJson("/api/users/{$agent->id}", ['password' => NEW_PASSWORD])->assertOk();
    expect(Hash::check(NEW_PASSWORD, $agent->refresh()->password))->toBeTrue();

    $entry = Activity::query()->where('event', 'password_reset')->latest('id')->first();
    expect($entry)->not->toBeNull()
        ->and(json_encode($entry->properties))->not->toContain(NEW_PASSWORD);
});

it('does not let update flip is_active', function () {
    $this->actingAsRole(RoleName::Admin);

    $this->patchJson('/api/users/'.$this->alpha->agent(0)->id, ['is_active' => false])
        ->assertUnprocessable()->assertJsonValidationErrors('is_active');
});

it('changes the role with exactly one role left and writes an audit entry', function () {
    $admin = $this->actingAsRole(RoleName::Support);
    $agent = $this->alpha->agent(0);

    $this->patchJson("/api/users/{$agent->id}", ['role' => 'team_lead'])->assertOk()->assertJsonPath('data.roles', ['team_lead']);

    expect($agent->refresh()->getRoleNames()->all())->toBe(['team_lead']);

    $entry = Activity::query()->where('log_name', 'user')->where('event', 'role_changed')->latest('id')->firstOrFail();
    expect($entry->causer_id)->toBe($admin->id)
        ->and($entry->subject_id)->toBe($agent->id)
        ->and($entry->properties->all())->toBe(['from' => ['sales_executive'], 'to' => ['team_lead']]);
});

it('frees the led team when a team lead changes role or team', function () {
    $this->actingAsRole(RoleName::Admin);
    $lead = $this->alpha->teamLead;

    $this->patchJson("/api/users/{$lead->id}", ['role' => 'sales_executive'])->assertOk();

    expect($this->alpha->team->refresh()->team_lead_id)->toBeNull();
});

it('keeps support away from admin and support accounts and roles', function () {
    $this->actingAsRole(RoleName::Support);
    $admin = $this->makeUser(RoleName::Admin);
    $otherSupport = $this->makeUser(RoleName::Support);
    $agent = $this->alpha->agent(0);

    $this->patchJson("/api/users/{$admin->id}", ['name' => 'Hacked'])->assertForbidden();
    $this->patchJson("/api/users/{$otherSupport->id}", ['name' => 'Hacked'])->assertForbidden();
    $this->patchJson("/api/users/{$agent->id}", ['role' => 'admin'])->assertForbidden();
    $this->patchJson("/api/users/{$agent->id}", ['role' => 'support'])->assertForbidden();
    $this->patchJson("/api/users/{$admin->id}/deactivate")->assertForbidden();
    $this->deleteJson("/api/users/{$agent->id}")->assertForbidden();

    expect($agent->refresh()->getRoleNames()->all())->toBe(['sales_executive']);
});

it('denies update to team leads and sales executives', function () {
    $this->actingAsUser($this->alpha->teamLead);
    $this->patchJson('/api/users/'.$this->alpha->agent(0)->id, ['name' => 'Nope'])->assertForbidden();

    $this->actingAsUser($this->alpha->agent(0));
    $this->patchJson('/api/users/'.$this->alpha->agent(0)->id, ['name' => 'Nope'])->assertForbidden();
});

it('blocks removing the admin role from the last active admin', function () {
    $admin = $this->actingAsRole(RoleName::Admin);

    $this->patchJson("/api/users/{$admin->id}", ['role' => 'support'])->assertForbidden();
    expect($admin->refresh()->hasRole('admin'))->toBeTrue();

    $second = $this->makeUser(RoleName::Admin);
    $this->patchJson("/api/users/{$second->id}", ['role' => 'support'])->assertOk();
});

it('deletes softly, admin only, never yourself, never the last admin', function () {
    $admin = $this->actingAsRole(RoleName::Admin);
    $agent = $this->alpha->agent(0);

    $this->deleteJson("/api/users/{$admin->id}")->assertForbidden();
    $this->deleteJson("/api/users/{$agent->id}")->assertNoContent();

    expect(User::query()->find($agent->id))->toBeNull()
        ->and(User::withTrashed()->find($agent->id))->not->toBeNull();
    $this->getJson("/api/users/{$agent->id}")->assertNotFound();

    $other = $this->makeUser(RoleName::Admin);
    $this->actingAsUser($other);
    $this->deleteJson("/api/users/{$admin->id}")->assertNoContent();
    $this->actingAsUser($other);
    $this->deleteJson("/api/users/{$other->id}")->assertForbidden();
});

it('denies delete to support and team leads', function () {
    $this->actingAsRole(RoleName::Support);
    $this->deleteJson('/api/users/'.$this->alpha->agent(0)->id)->assertForbidden();

    $this->actingAsUser($this->alpha->teamLead);
    $this->deleteJson('/api/users/'.$this->alpha->agent(0)->id)->assertForbidden();
});

it('frees the led team when its lead is deleted', function () {
    $this->actingAsRole(RoleName::Admin);

    $this->deleteJson('/api/users/'.$this->alpha->teamLead->id)->assertNoContent();

    expect(Team::query()->find($this->alpha->team->id)->team_lead_id)->toBeNull();
});
