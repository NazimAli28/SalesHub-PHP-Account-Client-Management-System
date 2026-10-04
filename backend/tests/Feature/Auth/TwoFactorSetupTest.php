<?php

use App\Enums\RoleName;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Activitylog\Models\Activity;

function twoFactorSetupOtp(string $secret): string
{
    return (new Google2FA)->getCurrentOtp($secret);
}

function twoFactorSetupLogs(string $event)
{
    return Activity::query()->where('log_name', 'auth')->where('event', $event);
}

it('requires authentication for every two-factor endpoint', function (string $method, string $uri) {
    $this->spa($method, $uri, ['password' => 'Demo@12345', 'code' => '123456'])->assertUnauthorized();
})->with([
    'start' => ['POST', '/api/auth/two-factor'],
    'confirm' => ['POST', '/api/auth/two-factor/confirm'],
    'disable' => ['DELETE', '/api/auth/two-factor'],
    'view codes' => ['POST', '/api/auth/two-factor/recovery-codes/view'],
    'regenerate codes' => ['POST', '/api/auth/two-factor/recovery-codes'],
]);

it('starts setup with a secret, QR code and otpauth URL, stored encrypted and not yet active', function () {
    $user = $this->makeUser(RoleName::SalesExecutive, ['email' => 'agent1@example.com']);

    $response = $this->spaAs($user)->spa('POST', '/api/auth/two-factor')->assertOk();

    $secret = $response->json('data.secret');

    expect($secret)->toMatch('/^[A-Z2-7]{32}$/')
        ->and($response->json('data.qr_code'))->toStartWith('data:image/svg+xml;base64,')
        ->and(base64_decode(substr($response->json('data.qr_code'), 26)))->toContain('<svg')
        ->and($response->json('data.otpauth_url'))->toStartWith('otpauth://totp/SalesHub:agent1%40example.com?secret='.$secret)
        ->and($response->json('data.otpauth_url'))->toContain('issuer=SalesHub');

    $raw = DB::table('users')->where('id', $user->id)->value('two_factor_secret');

    expect($raw)->not->toBeNull()->not->toContain($secret)
        ->and($user->fresh()->two_factor_secret)->toBe($secret)
        ->and($user->fresh()->hasTwoFactorEnabled())->toBeFalse();

    $this->spa('GET', '/api/auth/me')->assertJsonPath('data.two_factor_enabled', false);
});

it('confirms setup with a valid code and returns eight recovery codes once', function () {
    $user = $this->makeUser(RoleName::SalesExecutive);
    $secret = $this->spaAs($user)->spa('POST', '/api/auth/two-factor')->json('data.secret');

    $response = $this->spa('POST', '/api/auth/two-factor/confirm', ['code' => twoFactorSetupOtp($secret)]);

    $response->assertOk()->assertJsonCount(8, 'data.recovery_codes');

    expect($response->json('data.recovery_codes.0'))->toMatch('/^[A-Z0-9]{5}-[A-Z0-9]{5}$/')
        ->and($response->getContent())->not->toContain($secret)
        ->and($user->fresh()->hasTwoFactorEnabled())->toBeTrue()
        ->and($user->fresh()->two_factor_recovery_codes)->toBe($response->json('data.recovery_codes'))
        ->and(twoFactorSetupLogs('two_factor_enabled')->where('causer_id', $user->id)->count())->toBe(1);

    $this->spa('GET', '/api/auth/me')->assertJsonPath('data.two_factor_enabled', true);
});

it('rejects a wrong confirmation code', function () {
    $user = $this->makeUser(RoleName::SalesExecutive);
    $this->spaAs($user)->spa('POST', '/api/auth/two-factor')->assertOk();

    $this->spa('POST', '/api/auth/two-factor/confirm', ['code' => '000000'])
        ->assertStatus(422)->assertJsonValidationErrors('code');
    $this->spa('POST', '/api/auth/two-factor/confirm', [])
        ->assertStatus(422)->assertJsonValidationErrors('code');

    expect($user->fresh()->hasTwoFactorEnabled())->toBeFalse();
});

it('refuses to confirm before setup started', function () {
    $user = $this->makeUser(RoleName::SalesExecutive);

    $this->spaAs($user)->spa('POST', '/api/auth/two-factor/confirm', ['code' => '123456'])
        ->assertStatus(409)->assertJsonPath('code', 'two_factor_not_started');
});

it('refuses to restart setup while two-factor is on', function () {
    $user = User::factory()->salesExecutive()->withTwoFactor()->create();
    $secret = $user->two_factor_secret;

    $this->spaAs($user)->spa('POST', '/api/auth/two-factor')
        ->assertStatus(409)->assertJsonPath('code', 'two_factor_already_enabled');
    $this->spa('POST', '/api/auth/two-factor/confirm', ['code' => twoFactorSetupOtp($secret)])
        ->assertStatus(409);

    expect($user->fresh()->two_factor_secret)->toBe($secret);
});

it('requires the current password to turn two-factor off', function () {
    $user = User::factory()->salesExecutive()->withTwoFactor()->create();

    $this->spaAs($user)->spa('DELETE', '/api/auth/two-factor')
        ->assertStatus(422)->assertJsonValidationErrors('password');
    $this->spa('DELETE', '/api/auth/two-factor', ['password' => 'Wrong-Passw0rd!'])
        ->assertStatus(422)->assertJsonValidationErrors('password');

    expect($user->fresh()->hasTwoFactorEnabled())->toBeTrue();
});

it('turns two-factor off and logs it', function () {
    $user = User::factory()->salesExecutive()->withTwoFactor()->create();

    $this->spaAs($user)->spa('DELETE', '/api/auth/two-factor', ['password' => 'Demo@12345'])->assertNoContent();

    $fresh = $user->fresh();

    expect($fresh->hasTwoFactorEnabled())->toBeFalse()
        ->and($fresh->two_factor_secret)->toBeNull()
        ->and($fresh->two_factor_recovery_codes)->toBeNull()
        ->and(twoFactorSetupLogs('two_factor_disabled')->where('causer_id', $user->id)->count())->toBe(1);
});

it('shows the recovery codes after a password check', function () {
    $user = User::factory()->salesExecutive()->withTwoFactor()->create();

    $this->spaAs($user)->spa('POST', '/api/auth/two-factor/recovery-codes/view')
        ->assertStatus(422)->assertJsonValidationErrors('password');
    $this->spa('POST', '/api/auth/two-factor/recovery-codes/view', ['password' => 'nope'])
        ->assertStatus(422)->assertJsonValidationErrors('password');

    $this->spa('POST', '/api/auth/two-factor/recovery-codes/view', ['password' => 'Demo@12345'])
        ->assertOk()
        ->assertJsonPath('data.recovery_codes', $user->two_factor_recovery_codes);
});

it('regenerates the recovery codes after a password check', function () {
    $user = User::factory()->salesExecutive()->withTwoFactor()->create();
    $old = $user->two_factor_recovery_codes;

    $this->spaAs($user)->spa('POST', '/api/auth/two-factor/recovery-codes', ['password' => 'nope'])
        ->assertStatus(422)->assertJsonValidationErrors('password');

    $response = $this->spa('POST', '/api/auth/two-factor/recovery-codes', ['password' => 'Demo@12345'])
        ->assertOk()->assertJsonCount(8, 'data.recovery_codes');

    $new = $response->json('data.recovery_codes');

    expect(array_intersect($old, $new))->toBe([])
        ->and($user->fresh()->two_factor_recovery_codes)->toBe($new)
        ->and(twoFactorSetupLogs('two_factor_recovery_codes_regenerated')->where('causer_id', $user->id)->count())->toBe(1);

    $log = twoFactorSetupLogs('two_factor_recovery_codes_regenerated')->firstOrFail();
    expect(json_encode($log->properties))->not->toContain($new[0]);
});

it('answers 409 for recovery codes while two-factor is off', function () {
    $user = $this->makeUser(RoleName::SalesExecutive);

    $this->spaAs($user)->spa('POST', '/api/auth/two-factor/recovery-codes/view', ['password' => 'Demo@12345'])
        ->assertStatus(409)->assertJsonPath('code', 'two_factor_not_enabled');
    $this->spa('POST', '/api/auth/two-factor/recovery-codes', ['password' => 'Demo@12345'])
        ->assertStatus(409);
});

it('never exposes the secret or recovery codes through user JSON', function () {
    $admin = $this->makeUser(RoleName::Admin);
    $user = User::factory()->salesExecutive()->withTwoFactor()->create();
    $secret = (string) $user->two_factor_secret;
    $code = $user->two_factor_recovery_codes[0];

    $me = $this->spaAs($user)->spa('GET', '/api/auth/me')->assertOk()->getContent();
    $shown = $this->spaAs($admin)->spa('GET', '/api/users/'.$user->id)->assertOk()->getContent();
    $list = $this->spa('GET', '/api/users')->assertOk()->getContent();

    foreach ([$me, $shown, $list, $user->toJson()] as $json) {
        expect($json)->not->toContain($secret)
            ->not->toContain($code)
            ->not->toContain('two_factor_secret')
            ->not->toContain('two_factor_recovery_codes');
    }

    $logs = Activity::query()->get()->toJson();

    expect($logs)->not->toContain($secret)->not->toContain($code);
});
