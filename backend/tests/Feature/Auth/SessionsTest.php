<?php

use App\Actions\Auth\DescribeUserAgent;
use App\Enums\RoleName;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

const TWO_FACTOR_SESSIONS_UA = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_5) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Safari/605.1.15';

function twoFactorSessionsInsert(string $id, User $user, int $minutesAgo = 10): void
{
    DB::table('sessions')->insert([
        'id' => $id,
        'user_id' => $user->id,
        'ip_address' => '203.0.113.7',
        'user_agent' => TWO_FACTOR_SESSIONS_UA,
        'payload' => '',
        'last_activity' => now()->subMinutes($minutesAgo)->getTimestamp(),
    ]);
}

it('requires authentication', function (string $method, string $uri) {
    $this->spa($method, $uri, ['password' => 'Demo@12345'])->assertUnauthorized();
})->with([
    'list' => ['GET', '/api/auth/sessions'],
    'revoke' => ['DELETE', '/api/auth/sessions/others'],
]);

it('lists only the current session with a non-database driver', function () {
    $user = $this->makeUser(RoleName::SalesExecutive);

    $this->spaAs($user)->spa('GET', '/api/auth/sessions', [], ['User-Agent' => TWO_FACTOR_SESSIONS_UA])
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.is_current', true)
        ->assertJsonPath('data.0.device', 'Safari on macOS');
});

describe('with database sessions', function () {
    beforeEach(function () {
        config(['session.driver' => 'database']);

        // JSON test requests only send the cookie jar with credentials on, and the session id must survive here.
        $this->withCredentials();
    });

    it('lists the sessions of the user with opaque ids, current first', function () {
        $user = $this->makeUser(RoleName::SalesExecutive, ['username' => 'agent1']);
        $other = $this->makeUser(RoleName::SalesExecutive);
        twoFactorSessionsInsert('raw-session-id-older', $user, 30);
        twoFactorSessionsInsert('raw-session-id-someone-else', $other);

        $this->spaLogin('agent1')->assertOk();

        $response = $this->spa('GET', '/api/auth/sessions', [], [
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36',
        ]);

        $response->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonStructure(['data' => [['id', 'ip_address', 'device', 'last_active_at', 'is_current']]])
            ->assertJsonPath('data.0.is_current', true)
            ->assertJsonPath('data.1.is_current', false)
            ->assertJsonPath('data.1.ip_address', '203.0.113.7')
            ->assertJsonPath('data.1.device', 'Safari on macOS');

        expect($response->getContent())->not->toContain('raw-session-id')
            ->not->toContain(session()->getId())
            ->and($response->json('data.1.last_active_at'))->toMatch('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/');
    });

    it('requires the password to sign out other sessions', function () {
        $user = $this->makeUser(RoleName::SalesExecutive, ['username' => 'agent1']);
        twoFactorSessionsInsert('other-a', $user);
        $this->spaLogin('agent1')->assertOk();

        $this->spa('DELETE', '/api/auth/sessions/others')->assertStatus(422)->assertJsonValidationErrors('password');
        $this->spa('DELETE', '/api/auth/sessions/others', ['password' => 'nope'])
            ->assertStatus(422)->assertJsonValidationErrors('password');

        expect(DB::table('sessions')->where('id', 'other-a')->exists())->toBeTrue();
    });

    it('signs out the other sessions only', function () {
        $user = $this->makeUser(RoleName::SalesExecutive, ['username' => 'agent1']);
        $other = $this->makeUser(RoleName::SalesExecutive);
        twoFactorSessionsInsert('other-a', $user);
        twoFactorSessionsInsert('other-b', $user);
        twoFactorSessionsInsert('someone-else', $other);
        $this->spaLogin('agent1')->assertOk();
        $rememberToken = $user->fresh()->remember_token;

        $this->spa('DELETE', '/api/auth/sessions/others', ['password' => 'Demo@12345'])
            ->assertOk()
            ->assertJsonPath('data.revoked', 2);

        expect(DB::table('sessions')->whereIn('id', ['other-a', 'other-b'])->count())->toBe(0)
            ->and(DB::table('sessions')->where('id', 'someone-else')->exists())->toBeTrue()
            ->and($user->fresh()->remember_token)->not->toBe($rememberToken);

        $this->spa('GET', '/api/auth/me')->assertOk();
        $this->spa('GET', '/api/auth/sessions')->assertJsonCount(1, 'data');

        $log = Activity::query()->where('log_name', 'auth')->where('event', 'sessions_revoked')->firstOrFail();

        expect($log->causer_id)->toBe($user->id)
            ->and($log->properties->get('count'))->toBe(2);
    });
});

it('labels user agents', function (?string $userAgent, string $label) {
    expect(DescribeUserAgent::label($userAgent))->toBe($label);
})->with([
    'chrome windows' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36', 'Chrome on Windows'],
    'edge windows' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36 Edg/130.0', 'Edge on Windows'],
    'firefox linux' => ['Mozilla/5.0 (X11; Linux x86_64; rv:131.0) Gecko/20100101 Firefox/131.0', 'Firefox on Linux'],
    'safari iphone' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1', 'Safari on iOS'],
    'chrome android' => ['Mozilla/5.0 (Linux; Android 14) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Mobile Safari/537.36', 'Chrome on Android'],
    'empty' => [null, 'Unknown device'],
    'unknown' => ['SomeBot/1.0', 'Unknown device'],
]);
