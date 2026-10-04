<?php

use App\Enums\RoleName;
use App\Models\User;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Activitylog\Models\Activity;

function twoFactorChallengeUser(array $attributes = []): User
{
    $user = User::factory()->salesExecutive()->withTwoFactor()->create([
        'username' => 'agent1', 'email' => 'agent1@example.com', 'last_login_at' => null, ...$attributes,
    ]);

    return $user;
}

function twoFactorChallengeOtp(User $user): string
{
    return (new Google2FA)->getCurrentOtp((string) $user->two_factor_secret);
}

function twoFactorChallengeLogs(string $event)
{
    return Activity::query()->where('log_name', 'auth')->where('event', $event);
}

// Send the cookie jar with JSON requests so the pending sign-in really travels in the session cookie.
beforeEach(fn () => $this->withCredentials());

it('asks for a second factor instead of signing in', function () {
    $user = twoFactorChallengeUser();

    $this->spaLogin('agent1')
        ->assertOk()
        ->assertExactJson(['data' => ['two_factor' => true]]);

    $this->spa('GET', '/api/auth/me')->assertUnauthorized();

    expect($user->fresh()->last_login_at)->toBeNull()
        ->and(twoFactorChallengeLogs('two_factor_challenged')->where('causer_id', $user->id)->count())->toBe(1)
        ->and(twoFactorChallengeLogs('login')->count())->toBe(0);
});

it('signs in with a valid authenticator code', function () {
    $user = twoFactorChallengeUser();
    $this->spaLogin('agent1')->assertOk();

    $response = $this->spa('POST', '/api/auth/two-factor-challenge', ['code' => twoFactorChallengeOtp($user)]);

    $response->assertOk()
        ->assertJsonPath('data.id', $user->id)
        ->assertJsonPath('data.two_factor_enabled', true)
        ->assertJsonPath('data.roles', ['sales_executive']);

    $this->spa('GET', '/api/auth/me')->assertOk()->assertJsonPath('data.id', $user->id);

    $login = twoFactorChallengeLogs('login')->where('causer_id', $user->id)->firstOrFail();

    expect($user->fresh()->last_login_at)->not->toBeNull()
        ->and($login->properties->get('two_factor'))->toBe('totp');
});

it('regenerates the session id when the challenge passes', function () {
    $user = twoFactorChallengeUser();
    $this->spaLogin('agent1')->assertOk();
    $before = session()->getId();

    $this->spa('POST', '/api/auth/two-factor-challenge', ['code' => twoFactorChallengeOtp($user)])->assertOk();

    expect(session()->getId())->not->toBe($before);
});

it('carries the remember flag through the challenge', function () {
    $user = twoFactorChallengeUser();
    $this->spaLogin('agent1', 'Demo@12345', ['remember' => true])->assertOk();

    $response = $this->spa('POST', '/api/auth/two-factor-challenge', ['code' => twoFactorChallengeOtp($user)]);

    $cookies = collect($response->baseResponse->headers->getCookies())->map->getName();

    expect($cookies->contains(fn (string $name) => str_starts_with($name, 'remember_web_')))->toBeTrue();
});

it('rejects a wrong code and logs the failure without the code', function () {
    $user = twoFactorChallengeUser();
    $this->spaLogin('agent1')->assertOk();

    $this->spa('POST', '/api/auth/two-factor-challenge', ['code' => '000000'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('code');

    $this->spa('GET', '/api/auth/me')->assertUnauthorized();

    $log = twoFactorChallengeLogs('two_factor_failed')->where('causer_id', $user->id)->firstOrFail();

    expect($log->properties->get('method'))->toBe('totp')
        ->and(json_encode($log->properties))->not->toContain('000000');
});

it('rejects a code that was already used', function () {
    $user = twoFactorChallengeUser();
    $code = twoFactorChallengeOtp($user);

    $this->spaLogin('agent1')->assertOk();
    $this->spa('POST', '/api/auth/two-factor-challenge', ['code' => $code])->assertOk();
    $this->spa('POST', '/api/auth/logout')->assertNoContent();

    $this->spaLogin('agent1')->assertOk();
    $this->spa('POST', '/api/auth/two-factor-challenge', ['code' => $code])
        ->assertStatus(422)
        ->assertJsonValidationErrors('code');
});

it('expires a pending sign-in after five minutes', function () {
    $user = twoFactorChallengeUser();
    $this->spaLogin('agent1')->assertOk();

    $this->travel(6)->minutes();

    $this->spa('POST', '/api/auth/two-factor-challenge', ['code' => twoFactorChallengeOtp($user)])
        ->assertStatus(422)
        ->assertJsonPath('code', 'two_factor_expired');

    $this->spa('GET', '/api/auth/me')->assertUnauthorized();
});

it('rejects a challenge without a pending sign-in', function () {
    $user = twoFactorChallengeUser();

    $this->spa('POST', '/api/auth/two-factor-challenge', ['code' => twoFactorChallengeOtp($user)])
        ->assertStatus(422)
        ->assertJsonPath('code', 'two_factor_expired');
});

it('validates that a code or a recovery code is sent', function () {
    twoFactorChallengeUser();
    $this->spaLogin('agent1')->assertOk();

    $this->spa('POST', '/api/auth/two-factor-challenge', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['code', 'recovery_code']);
});

it('accepts each recovery code only once', function () {
    $user = twoFactorChallengeUser();
    $recoveryCode = $user->two_factor_recovery_codes[0];

    $this->spaLogin('agent1')->assertOk();
    $this->spa('POST', '/api/auth/two-factor-challenge', ['recovery_code' => strtolower($recoveryCode)])
        ->assertOk()
        ->assertJsonPath('data.id', $user->id);

    expect($user->fresh()->two_factor_recovery_codes)->toHaveCount(7)->not->toContain($recoveryCode)
        ->and(twoFactorChallengeLogs('two_factor_recovery_code_used')->where('causer_id', $user->id)->count())->toBe(1);

    $this->spa('POST', '/api/auth/logout')->assertNoContent();
    $this->spaLogin('agent1')->assertOk();

    $this->spa('POST', '/api/auth/two-factor-challenge', ['recovery_code' => $recoveryCode])
        ->assertStatus(422)
        ->assertJsonValidationErrors('recovery_code');
});

it('locks out the sixth wrong code', function () {
    $user = twoFactorChallengeUser();
    $this->spaLogin('agent1')->assertOk();

    for ($i = 0; $i < 5; $i++) {
        $this->spa('POST', '/api/auth/two-factor-challenge', ['code' => '000000'])->assertStatus(422);
    }

    $this->spa('POST', '/api/auth/two-factor-challenge', ['code' => twoFactorChallengeOtp($user)])
        ->assertStatus(429)
        ->assertHeader('Retry-After')
        ->assertJsonPath('code', 'too_many_attempts');
});

it('refuses the challenge when the user was deactivated in between', function () {
    $user = twoFactorChallengeUser();
    $this->spaLogin('agent1')->assertOk();

    $user->forceFill(['is_active' => false])->save();

    $this->spa('POST', '/api/auth/two-factor-challenge', ['code' => twoFactorChallengeOtp($user)])
        ->assertForbidden()
        ->assertJsonPath('code', 'account_inactive');
});

it('keeps the generic error for a wrong password on a two-factor account', function () {
    twoFactorChallengeUser();

    $this->spaLogin('agent1', 'Wrong-Passw0rd!')
        ->assertStatus(422)
        ->assertJsonMissingPath('data.two_factor')
        ->assertJsonPath('errors.login.0', 'These credentials do not match our records.');
});

it('still signs in users without two-factor directly', function () {
    $user = $this->makeUser(RoleName::SalesExecutive, ['username' => 'agent2']);

    $this->spaLogin('agent2')->assertOk()
        ->assertJsonPath('data.id', $user->id)
        ->assertJsonPath('data.two_factor_enabled', false);
});

it('never returns the secret or recovery codes in the sign-in responses', function () {
    $user = twoFactorChallengeUser();

    $first = $this->spaLogin('agent1');
    $second = $this->spa('POST', '/api/auth/two-factor-challenge', ['code' => twoFactorChallengeOtp($user)]);

    foreach ([$first, $second] as $response) {
        expect($response->getContent())
            ->not->toContain((string) $user->two_factor_secret)
            ->not->toContain($user->two_factor_recovery_codes[1])
            ->not->toContain('two_factor_secret')
            ->not->toContain('two_factor_recovery_codes');
    }
});
