<?php

use App\Enums\RoleName;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Spatie\Activitylog\Models\Activity;

const NEW_PASSWORD = 'N3w-Passw0rd!x';

function passwordPayload(array $overrides = []): array
{
    return [
        'current_password' => 'Demo@12345',
        'password' => NEW_PASSWORD,
        'password_confirmation' => NEW_PASSWORD,
        ...$overrides,
    ];
}

it('rejects a wrong current password', function () {
    $user = $this->makeUser(RoleName::SalesExecutive);

    $this->spaAs($user)->spa('PUT', '/api/auth/password', passwordPayload(['current_password' => 'nope']))
        ->assertStatus(422)->assertJsonValidationErrors('current_password');
});

it('enforces each password rule', function (string $password, string $reason) {
    $user = $this->makeUser(RoleName::SalesExecutive);

    $this->spaAs($user)->spa('PUT', '/api/auth/password', passwordPayload([
        'password' => $password, 'password_confirmation' => $password,
    ]))->assertStatus(422)->assertJsonValidationErrors('password');
})->with([
    'nine characters' => ['Abcdef1!x', 'min 10'],
    'no symbol' => ['Abcdefgh12', 'symbol'],
    'no uppercase' => ['abcdefgh1!', 'mixed case'],
    'no number' => ['Abcdefgh!!', 'number'],
]);

it('requires a matching confirmation', function () {
    $user = $this->makeUser(RoleName::SalesExecutive);

    $this->spaAs($user)->spa('PUT', '/api/auth/password', passwordPayload(['password_confirmation' => 'different']))
        ->assertStatus(422)->assertJsonValidationErrors('password');
});

it('rejects a new password equal to the current one', function () {
    $user = $this->makeUser(RoleName::SalesExecutive);

    $this->spaAs($user)->spa('PUT', '/api/auth/password', passwordPayload([
        'password' => 'Demo@12345', 'password_confirmation' => 'Demo@12345',
    ]))->assertStatus(422)->assertJsonValidationErrors('password');
});

it('changes the password, drops other sessions and logs the event', function () {
    $user = $this->makeUser(RoleName::SalesExecutive);
    $other = $this->makeUser(RoleName::SalesExecutive);

    foreach (['other-session-a', 'other-session-b'] as $id) {
        DB::table('sessions')->insert(['id' => $id, 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);
    }
    DB::table('sessions')->insert(['id' => 'someone-else', 'user_id' => $other->id, 'payload' => '', 'last_activity' => time()]);

    $this->spaAs($user)->spa('PUT', '/api/auth/password', passwordPayload())->assertNoContent();

    expect(Hash::check(NEW_PASSWORD, $user->fresh()->password))->toBeTrue()
        ->and(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(0)
        ->and(DB::table('sessions')->where('user_id', $other->id)->count())->toBe(1)
        ->and(Activity::query()->where('log_name', 'auth')->where('event', 'password_changed')->where('causer_id', $user->id)->count())->toBe(1);
});

it('requires authentication', function () {
    $this->spa('PUT', '/api/auth/password', passwordPayload())->assertUnauthorized();
});

it('only applies the uncompromised rule in production', function () {
    $uncompromised = function (): bool {
        $rule = Password::default();
        $property = new ReflectionProperty($rule, 'uncompromised');

        return (bool) $property->getValue($rule);
    };

    expect($uncompromised())->toBeFalse();

    $this->app['env'] = 'production';

    expect($uncompromised())->toBeTrue();
});
