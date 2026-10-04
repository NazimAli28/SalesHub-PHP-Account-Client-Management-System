<?php

use App\Enums\RoleName;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->alpha = $this->makeTeamWithMembers(2);
});

function fakeSession(User $user): string
{
    $id = bin2hex(random_bytes(20));
    DB::table('sessions')->insert([
        'id' => $id, 'user_id' => $user->id, 'ip_address' => '127.0.0.1',
        'user_agent' => 'pest', 'payload' => 'x', 'last_activity' => time(),
    ]);

    return $id;
}

it('deactivates a user, deletes their sessions and tokens, and audits it', function () {
    $support = $this->actingAsRole(RoleName::Support);
    $agent = $this->alpha->agent(0);
    $other = $this->alpha->agent(1);
    fakeSession($agent);
    fakeSession($agent);
    $keep = fakeSession($other);
    $agent->createToken('api');

    $this->patchJson("/api/users/{$agent->id}/deactivate")->assertOk()->assertJsonPath('data.is_active', false);

    expect($agent->refresh()->is_active)->toBeFalse()
        ->and(DB::table('sessions')->where('user_id', $agent->id)->count())->toBe(0)
        ->and(DB::table('sessions')->where('id', $keep)->exists())->toBeTrue()
        ->and($agent->tokens()->count())->toBe(0);

    $entry = Activity::query()->where('log_name', 'user')->where('event', 'deactivated')->firstOrFail();
    expect($entry->causer_id)->toBe($support->id)->and($entry->subject_id)->toBe($agent->id);
});

it('answers 401 on the next request of a deactivated user', function () {
    $agent = $this->alpha->agent(0);

    $this->actingAsUser($agent);
    $this->getJson('/api/auth/me')->assertOk();

    $this->actingAsRole(RoleName::Support);
    $this->patchJson("/api/users/{$agent->id}/deactivate")->assertOk();

    $this->actingAsUser($agent->refresh());
    $this->getJson('/api/auth/me')->assertUnauthorized();
});

it('reactivates a user and audits it', function () {
    $this->actingAsRole(RoleName::Support);
    $agent = $this->makeStaff(RoleName::SalesExecutive, $this->alpha->team, null, ['is_active' => false]);

    $this->patchJson("/api/users/{$agent->id}/activate")->assertOk()->assertJsonPath('data.is_active', true);

    expect($agent->refresh()->is_active)->toBeTrue()
        ->and(Activity::query()->where('event', 'activated')->where('subject_id', $agent->id)->exists())->toBeTrue();
});

it('is idempotent', function () {
    $this->actingAsRole(RoleName::Admin);
    $agent = $this->alpha->agent(0);

    $this->patchJson("/api/users/{$agent->id}/activate")->assertOk();
    $this->patchJson("/api/users/{$agent->id}/deactivate")->assertOk();
    $this->patchJson("/api/users/{$agent->id}/deactivate")->assertOk();

    expect(Activity::query()->where('event', 'deactivated')->count())->toBe(1);
});

it('does not let anyone deactivate themselves', function (RoleName $role) {
    $actor = $this->actingAsRole($role);

    $this->patchJson("/api/users/{$actor->id}/deactivate")->assertForbidden();
    expect($actor->refresh()->is_active)->toBeTrue();
})->with([RoleName::Support, RoleName::Admin]);

it('does not let the last active admin be deactivated', function () {
    $lastAdmin = $this->makeUser(RoleName::Admin);
    $support = $this->makeUser(RoleName::Support);

    // Support cannot touch admins at all; an admin cannot deactivate or delete themselves: all denied.
    $this->actingAsUser($support);
    $this->patchJson("/api/users/{$lastAdmin->id}/deactivate")->assertForbidden();

    $this->actingAsUser($lastAdmin);
    $this->patchJson("/api/users/{$lastAdmin->id}/deactivate")->assertForbidden();
    $this->deleteJson("/api/users/{$lastAdmin->id}")->assertForbidden();

    expect($lastAdmin->refresh()->is_active)->toBeTrue();
});

it('lets an admin deactivate another admin when more than one is active', function () {
    $this->actingAsRole(RoleName::Admin);
    $other = $this->makeUser(RoleName::Admin);

    $this->patchJson("/api/users/{$other->id}/deactivate")->assertOk();
});

it('denies deactivation to team leads and sales executives', function () {
    $this->actingAsUser($this->alpha->teamLead);
    $this->patchJson('/api/users/'.$this->alpha->agent(0)->id.'/deactivate')->assertForbidden();
    $this->patchJson('/api/users/'.$this->alpha->agent(0)->id.'/activate')->assertForbidden();

    $this->actingAsUser($this->alpha->agent(1));
    $this->patchJson('/api/users/'.$this->alpha->agent(0)->id.'/deactivate')->assertForbidden();
});

it('ends access when a user is deleted', function () {
    $this->actingAsRole(RoleName::Admin);
    $agent = $this->alpha->agent(0);
    fakeSession($agent);

    $this->deleteJson("/api/users/{$agent->id}")->assertNoContent();

    expect(DB::table('sessions')->where('user_id', $agent->id)->count())->toBe(0);
});

it('ends other sessions when an admin resets a password but keeps the own session on self-update', function () {
    $admin = $this->actingAsRole(RoleName::Admin);
    $agent = $this->alpha->agent(0);
    fakeSession($agent);
    $mine = fakeSession($admin);

    $this->patchJson("/api/users/{$agent->id}", ['password' => 'Str0ng!Passw0rd'])->assertOk();
    $this->patchJson("/api/users/{$admin->id}", ['password' => 'Str0ng!Passw0rd'])->assertOk();

    expect(DB::table('sessions')->where('user_id', $agent->id)->count())->toBe(0)
        ->and(DB::table('sessions')->where('id', $mine)->exists())->toBeTrue();
});
