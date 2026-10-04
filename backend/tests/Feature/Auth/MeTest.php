<?php

use App\Enums\RoleName;
use App\Models\Team;
use App\Models\Workstation;
use App\Support\PermissionMatrix;

it('rejects unauthenticated access to /me', function () {
    $this->spa('GET', '/api/auth/me')
        ->assertUnauthorized()
        ->assertExactJson(['message' => 'Unauthenticated.']);
});

it('returns the signed-in user with roles, sorted permissions, team and workstation', function () {
    $team = Team::factory()->create(['name' => 'Unit 1 Alpha', 'floor' => 3]);
    $station = Workstation::factory()->create(['team_id' => $team->id, 'code' => 'PC-01']);
    $agent = $this->makeStaff(RoleName::SalesExecutive, $team, $station);

    $response = $this->spaAs($agent)->spa('GET', '/api/auth/me');

    $response->assertOk()->assertJsonStructure(['data' => [
        'id', 'name', 'username', 'email', 'avatar_url', 'is_active', 'last_login_at',
        'roles', 'permissions', 'team' => ['id', 'name', 'floor', 'shift'], 'workstation' => ['id', 'code'],
    ]]);

    $permissions = $response->json('data.permissions');
    $expected = PermissionMatrix::forRole(RoleName::SalesExecutive);
    sort($expected);

    expect($permissions)->toBe($expected)
        ->and($response->json('data.roles'))->toBe(['sales_executive'])
        ->and($response->json('data.team.id'))->toBe($team->id)
        ->and($response->json('data.workstation.id'))->toBe($station->id)
        ->and($response->getContent())->not->toContain('password')
        ->and($response->getContent())->not->toContain('remember_token');
});

it('returns null team and workstation for users without them', function () {
    $admin = $this->makeUser(RoleName::Admin);

    $this->spaAs($admin)->spa('GET', '/api/auth/me')
        ->assertOk()
        ->assertJsonPath('data.team', null)
        ->assertJsonPath('data.workstation', null)
        ->assertJsonPath('data.roles', ['admin']);
});
