<?php

use App\Enums\RoleName;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

it('exposes demo mode as off by default', function () {
    $user = $this->makeUser(RoleName::SalesExecutive);

    $this->spaAs($user)->spa('GET', '/api/auth/me')
        ->assertOk()
        ->assertJsonPath('data.demo_mode', false)
        ->assertJsonPath('data.two_factor_enabled', false);
});

describe('with demo mode on', function () {
    beforeEach(fn () => config(['saleshub.demo_mode' => true]));

    it('tells the SPA', function () {
        $user = $this->makeUser(RoleName::SalesExecutive);

        $this->spaAs($user)->spa('GET', '/api/auth/me')->assertJsonPath('data.demo_mode', true);
    });

    it('refuses to start or confirm two-factor setup', function () {
        $user = $this->makeUser(RoleName::SalesExecutive);

        $this->spaAs($user)->spa('POST', '/api/auth/two-factor')
            ->assertForbidden()
            ->assertJsonPath('code', 'demo_mode');
        $this->spa('POST', '/api/auth/two-factor/confirm', ['code' => '123456'])
            ->assertForbidden()
            ->assertJsonPath('code', 'demo_mode');

        expect($user->fresh()->two_factor_secret)->toBeNull();
    });

    it('refuses to change the password, even with a valid payload', function () {
        $user = $this->makeUser(RoleName::SalesExecutive);

        $this->spaAs($user)->spa('PUT', '/api/auth/password', [
            'current_password' => 'Demo@12345',
            'password' => 'N3w-Passw0rd!x',
            'password_confirmation' => 'N3w-Passw0rd!x',
        ])->assertForbidden()->assertJsonPath('code', 'demo_mode');

        expect(Hash::check('Demo@12345', $user->fresh()->password))->toBeTrue();
    });

    it('still lets a user turn two-factor off', function () {
        $user = User::factory()->salesExecutive()->withTwoFactor()->create();

        $this->spaAs($user)->spa('DELETE', '/api/auth/two-factor', [
            'password' => 'Demo@12345',
            'code' => $user->two_factor_recovery_codes[0],
        ])->assertNoContent();

        expect($user->fresh()->hasTwoFactorEnabled())->toBeFalse();
    });
});
