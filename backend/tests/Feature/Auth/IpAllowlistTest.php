<?php

use App\Enums\RoleName;
use Spatie\Activitylog\Models\Activity;

function enableIpAllowlist(array $ranges = ['10.0.0.0/8']): void
{
    config(['saleshub.ip_allowlist' => [
        'enabled' => true,
        'ranges' => $ranges,
        'roles' => ['sales_executive', 'team_lead'],
    ]]);
}

it('blocks a sales executive outside the allowlist but not an admin', function () {
    enableIpAllowlist();
    $agent = $this->makeUser(RoleName::SalesExecutive);
    $admin = $this->makeUser(RoleName::Admin);

    $this->spaAs($agent)->spa('GET', '/api/auth/me')
        ->assertForbidden()
        ->assertJsonPath('code', 'ip_not_allowed')
        ->assertJsonPath('message', 'Sign-in is only allowed from the office network.');

    expect(Activity::query()->where('log_name', 'auth')->where('event', 'login_blocked_ip')->count())->toBe(1);

    $this->spaAs($admin)->spa('GET', '/api/auth/me')->assertOk();
});

it('allows a restricted role from an allowed IP or CIDR range', function () {
    enableIpAllowlist();
    $agent = $this->makeUser(RoleName::TeamLead);

    $this->withServerVariables(['REMOTE_ADDR' => '10.20.30.40'])
        ->spaAs($agent)->spa('GET', '/api/auth/me')->assertOk();
});

it('does nothing while disabled', function () {
    config(['saleshub.ip_allowlist.enabled' => false]);

    $this->spaAs($this->makeUser(RoleName::SalesExecutive))->spa('GET', '/api/auth/me')->assertOk();
});

it('blocks sign-in from a disallowed IP', function () {
    enableIpAllowlist();
    $this->makeUser(RoleName::SalesExecutive, ['username' => 'agent1']);

    $this->spaLogin('agent1')->assertForbidden()->assertJsonPath('code', 'ip_not_allowed');
    $this->spa('GET', '/api/auth/me')->assertUnauthorized();
});

it('signs the session out when the allowlist blocks an authenticated request', function () {
    $this->makeUser(RoleName::SalesExecutive, ['username' => 'agent1']);
    $this->spaLogin('agent1')->assertOk();

    enableIpAllowlist();

    $this->spa('GET', '/api/auth/me')->assertForbidden();

    config(['saleshub.ip_allowlist.enabled' => false]);
    $this->spa('GET', '/api/auth/me')->assertUnauthorized();
});
