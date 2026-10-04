<?php

use App\Enums\RoleName;
use App\Models\Team;
use App\Models\User;
use App\Models\Workstation;
use Spatie\Activitylog\Models\Activity;

function authLogs(string $event)
{
    return Activity::query()->where('log_name', 'auth')->where('event', $event);
}

it('logs in with an email address', function () {
    $team = Team::factory()->create(['name' => 'Unit 1 Alpha', 'floor' => 3]);
    $station = Workstation::factory()->create(['team_id' => $team->id, 'code' => 'PC-01']);
    $user = $this->makeStaff(RoleName::SalesExecutive, $team, $station, [
        'username' => 'agent1', 'email' => 'agent1@example.com', 'last_login_at' => null,
    ]);

    $response = $this->spaLogin('agent1@example.com', 'Demo@12345', ['remember' => false]);

    $response->assertOk()
        ->assertJsonPath('data.id', $user->id)
        ->assertJsonPath('data.username', 'agent1')
        ->assertJsonPath('data.roles', ['sales_executive'])
        ->assertJsonPath('data.team.name', 'Unit 1 Alpha')
        ->assertJsonPath('data.workstation.code', 'PC-01')
        ->assertCookie(config('session.cookie'));

    expect($user->fresh()->last_login_at)->not->toBeNull()
        ->and($user->fresh()->last_login_ip)->not->toBeNull()
        ->and(authLogs('login')->where('causer_id', $user->id)->count())->toBe(1);
});

it('logs in with a username and ignores case', function () {
    User::factory()->salesExecutive()->create(['username' => 'agent1', 'email' => 'agent1@example.com']);

    $this->spaLogin('AGENT1')->assertOk();
    $this->spaLogin('Agent1@Example.COM')->assertOk();
});

it('returns the same generic 422 for a wrong password and an unknown identifier', function () {
    User::factory()->salesExecutive()->create(['username' => 'agent1', 'email' => 'agent1@example.com']);

    $wrong = $this->spaLogin('agent1', 'Wrong-Passw0rd!');
    $unknown = $this->spaLogin('nobody', 'Wrong-Passw0rd!');
    $unknownEmail = $this->spaLogin('nobody@example.com', 'Wrong-Passw0rd!');

    $wrong->assertStatus(422)->assertJsonPath('errors.login.0', 'These credentials do not match our records.');
    expect($unknown->status())->toBe(422)
        ->and($unknown->json())->toBe($wrong->json())
        ->and($unknownEmail->json())->toBe($wrong->json());

    expect(authLogs('login_failed')->count())->toBe(3);
});

it('never stores the password in the failed login log', function () {
    $this->spaLogin('agent1', 'Super-Secret-1!')->assertStatus(422);

    $log = authLogs('login_failed')->firstOrFail();

    expect($log->properties->get('identifier'))->toBe('agent1')
        ->and(json_encode($log->properties))->not->toContain('Super-Secret-1!');
});

it('validates the login payload', function () {
    $this->spaLogin('', '')->assertStatus(422)->assertJsonValidationErrors(['login', 'password']);
});

it('locks out the sixth attempt for the same login and IP', function () {
    for ($i = 0; $i < 5; $i++) {
        $this->spaLogin('agent1', 'Wrong-Passw0rd!')->assertStatus(422);
    }

    $response = $this->spaLogin('agent1', 'Wrong-Passw0rd!');

    $response->assertStatus(429)
        ->assertHeader('Retry-After')
        ->assertJsonPath('code', 'too_many_attempts');

    expect($response->json('retry_after'))->toBeInt()->toBeGreaterThan(0)
        ->and($response->json('message'))->toContain('Too many login attempts. Please try again in');

    // A correct password is blocked as well while locked out.
    User::factory()->salesExecutive()->create(['username' => 'agent1']);
    $this->spaLogin('agent1')->assertStatus(429);

    expect(authLogs('login_locked_out')->count())->toBe(1);
});

it('locks out an IP after 20 attempts across different logins', function () {
    for ($i = 1; $i <= 20; $i++) {
        $this->spaLogin("user-{$i}", 'Wrong-Passw0rd!')->assertStatus(422);
    }

    $this->spaLogin('user-21', 'Wrong-Passw0rd!')->assertStatus(429)->assertHeader('Retry-After');
});

it('clears the per-login counter after a successful login', function () {
    User::factory()->salesExecutive()->create(['username' => 'agent1']);

    for ($i = 0; $i < 4; $i++) {
        $this->spaLogin('agent1', 'Wrong-Passw0rd!')->assertStatus(422);
    }
    $this->spaLogin('agent1')->assertOk();

    // Without the reset the next attempts would already be locked out (5 hits so far).
    for ($i = 0; $i < 5; $i++) {
        $this->spaLogin('agent1', 'Wrong-Passw0rd!')->assertStatus(422);
    }
});

it('rejects an inactive user with the correct password and leaves them signed out', function () {
    User::factory()->salesExecutive()->inactive()->create(['username' => 'agent7']);

    $this->spaLogin('agent7')
        ->assertForbidden()
        ->assertJsonPath('code', 'account_inactive');

    $this->spa('GET', '/api/auth/me')->assertUnauthorized();

    expect(authLogs('login_blocked_inactive')->count())->toBe(1);
});

it('signs a deactivated user out on their next request', function () {
    $user = User::factory()->salesExecutive()->create(['username' => 'agent1']);

    $this->spaLogin('agent1')->assertOk();
    $this->spa('GET', '/api/auth/me')->assertOk();

    $user->update(['is_active' => false]);

    $this->spa('GET', '/api/auth/me')
        ->assertUnauthorized()
        ->assertExactJson(['message' => 'Unauthenticated.']);
    $this->spa('GET', '/api/auth/me')->assertUnauthorized();
});

it('does not authenticate soft-deleted users', function () {
    User::factory()->salesExecutive()->create(['username' => 'agent1'])->delete();

    $this->spaLogin('agent1')->assertStatus(422);
});

it('requires a CSRF token for a stateful login', function () {
    User::factory()->salesExecutive()->create(['username' => 'agent1']);

    // The test environment skips CSRF validation unless told otherwise.
    $this->app['env'] = 'local';

    $this->spaLogin('agent1')->assertStatus(419);

    $this->spa('GET', '/sanctum/csrf-cookie')->assertNoContent();
    expect($this->spaCookies)->toHaveKey('XSRF-TOKEN');

    $this->spaLogin('agent1')->assertOk();
});

it('regenerates the session id on login and ends the session on logout', function () {
    User::factory()->salesExecutive()->create(['username' => 'agent1']);

    $this->spa('GET', '/sanctum/csrf-cookie')->assertNoContent();
    $before = session()->getId();

    $this->spaLogin('agent1')->assertOk();
    $after = session()->getId();

    expect($after)->not->toBe($before);

    $this->spa('GET', '/api/auth/me')->assertOk();
    $this->spa('POST', '/api/auth/logout')->assertNoContent();
    $this->spa('GET', '/api/auth/me')->assertUnauthorized();

    $user = User::query()->where('username', 'agent1')->firstOrFail();
    expect(authLogs('logout')->where('causer_id', $user->id)->count())->toBe(1);
});
