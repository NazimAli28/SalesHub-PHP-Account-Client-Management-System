<?php

use App\Http\Middleware\SecurityHeaders;
use App\Models\User;
use App\Support\IpMask;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Spatie\Activitylog\Models\Activity;

function securityInsertSession(string $id, User $user, string $ip): void
{
    DB::table('sessions')->insert([
        'id' => $id,
        'user_id' => $user->id,
        'ip_address' => $ip,
        'user_agent' => 'Mozilla/5.0 (X11; Linux x86_64) Firefox/131.0',
        'payload' => '',
        'last_activity' => now()->subMinutes(5)->getTimestamp(),
    ]);
}

describe('login throttling (S2)', function () {
    beforeEach(function () {
        // A sign-in attempt from a given client address (wrong password unless given).
        $this->loginFrom = fn (string $ip, string $login, string $password = 'Wrong-Passw0rd!'): TestResponse => $this
            ->withServerVariables(['REMOTE_ADDR' => $ip])
            ->spaLogin($login, $password);
    });

    it('never locks an account because of successful sign-ins', function () {
        User::factory()->salesExecutive()->create(['username' => 'agent1']);

        for ($i = 0; $i < 8; $i++) {
            $this->spaLogin('agent1')->assertOk();
            $this->spa('POST', '/api/auth/logout')->assertNoContent();
        }
    });

    it('locks a login for an hour after 30 failures from different addresses', function () {
        User::factory()->salesExecutive()->create(['username' => 'agent1']);

        for ($i = 1; $i <= 30; $i++) {
            // Four per address stays under the per-minute limit for one login and IP.
            ($this->loginFrom)('198.51.100.'.intdiv($i, 4), 'agent1')->assertStatus(422);
        }

        ($this->loginFrom)('198.51.100.200', 'agent1', 'Demo@12345')
            ->assertStatus(429)
            ->assertJsonPath('code', 'too_many_attempts');
    });

    it('never locks the demo accounts for an hour in demo mode', function () {
        config(['saleshub.demo_mode' => true]);
        User::factory()->salesExecutive()->create(['username' => 'agent1', 'email' => 'agent1@example.com']);

        foreach (['agent1', 'Agent1@Example.com'] as $login) {
            for ($i = 1; $i <= 31; $i++) {
                ($this->loginFrom)('198.51.100.'.intdiv($i, 4), $login)->assertStatus(422);
            }

            ($this->loginFrom)('198.51.100.200', $login, 'Demo@12345')->assertOk();
            $this->spa('POST', '/api/auth/logout')->assertNoContent();
        }

        // The per-login-and-IP limit still applies.
        for ($i = 0; $i < 5; $i++) {
            ($this->loginFrom)('203.0.113.9', 'agent1')->assertStatus(422);
        }
        ($this->loginFrom)('203.0.113.9', 'agent1')->assertStatus(429);
    });

    it('keeps login and IP counters apart, so a login named like an IP cannot block that IP', function () {
        User::factory()->salesExecutive()->create(['username' => 'agent1']);

        for ($i = 1; $i <= 20; $i++) {
            ($this->loginFrom)('198.51.100.'.intdiv($i, 4), '192.0.2.10')->assertStatus(422);
        }

        ($this->loginFrom)('192.0.2.10', 'agent1', 'Demo@12345')->assertOk();
    });

    it('logs a lockout without an identifier that matches no account', function () {
        for ($i = 0; $i < 6; $i++) {
            $this->spaLogin('S3cret-Typed-Here!');
        }

        $entry = securityAuthLogs('login_locked_out')->firstOrFail();
        expect($entry->properties->has('identifier'))->toBeFalse();
    });
});

describe('security headers on HTML pages (S3, S6)', function () {
    it('sends a strict same-origin policy with HTML pages', function () {
        $response = $this->get('/')->assertOk();

        expect($response->headers->get('Content-Security-Policy'))->toBe(SecurityHeaders::APP_CSP)
            ->and($response->headers->get('Content-Security-Policy'))->toContain("script-src 'self';")
            ->and($response->headers->get('Referrer-Policy'))->toBe('strict-origin-when-cross-origin')
            ->and($response->headers->get('Permissions-Policy'))->toBe(SecurityHeaders::PERMISSIONS_POLICY)
            ->and($response->headers->get('Cross-Origin-Opener-Policy'))->toBe('same-origin')
            ->and($response->headers->get('Cross-Origin-Resource-Policy'))->toBe('same-origin')
            ->and($response->headers->get('X-Frame-Options'))->toBe('DENY')
            ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
            ->and($response->headers->has('Strict-Transport-Security'))->toBeFalse();
    });

    it('sends HSTS over HTTPS outside the local environment', function () {
        $this->get('https://localhost/')->assertHeader('Strict-Transport-Security', SecurityHeaders::HSTS);
    });

    it('serves the API reference with SRI-pinned assets and a nonce-based policy', function () {
        config(['saleshub.public_api_docs' => true]);

        $response = $this->get('/docs/api')->assertOk();
        $csp = (string) $response->headers->get('Content-Security-Policy');
        $html = (string) $response->getContent();

        expect(preg_match("/'nonce-([A-Za-z0-9]+)'/", $csp, $match))->toBe(1)
            ->and($csp)->toContain("frame-ancestors 'none'")
            ->and($csp)->not->toContain("script-src 'self' 'unsafe-inline'")
            ->and(substr_count($html, 'nonce="'.$match[1].'"'))->toBeGreaterThanOrEqual(2)
            ->and($html)->toContain('@stoplight/elements@8.4.2/web-components.min.js')
            ->and(substr_count($html, 'integrity="sha384-'))->toBe(2)
            ->and(substr_count($html, 'crossorigin="anonymous"'))->toBe(2);

        // A fresh nonce for every page view.
        $again = (string) $this->get('/docs/api')->headers->get('Content-Security-Policy');
        expect($again)->not->toBe($csp);
    });

    it('still hides the API reference when it is not public', function () {
        config(['saleshub.public_api_docs' => false]);

        $this->get('/docs/api')->assertForbidden();
    });
});

describe('sessions in demo mode (S4)', function () {
    beforeEach(function () {
        config(['session.driver' => 'database', 'saleshub.demo_mode' => true]);
        $this->withCredentials();
    });

    it('lists only this browser, with a coarsened IP address', function () {
        $user = User::factory()->salesExecutive()->create(['username' => 'agent1']);
        securityInsertSession('another-visitor-session', $user, '203.0.113.50');

        $this->spaLogin('agent1')->assertOk();

        $sessions = $this->spa('GET', '/api/auth/sessions')->assertOk()->assertJsonCount(1, 'data')->json('data');

        expect($sessions[0]['is_current'])->toBeTrue()
            ->and($sessions[0]['ip_address'])->toBe('127.0.0.x');
    });

    it('refuses to sign out the other sessions', function () {
        $user = User::factory()->salesExecutive()->create(['username' => 'agent1']);
        securityInsertSession('another-visitor-session', $user, '203.0.113.50');
        $this->spaLogin('agent1')->assertOk();

        $this->spa('DELETE', '/api/auth/sessions/others', ['password' => 'Demo@12345'])
            ->assertForbidden()
            ->assertJsonPath('code', 'demo_mode');

        expect(DB::table('sessions')->where('id', 'another-visitor-session')->exists())->toBeTrue();
    });
});

it('masks IPv4 to /24 and IPv6 to /48', function (?string $ip, ?string $masked) {
    expect(IpMask::mask($ip))->toBe($masked);
})->with([
    'ipv4' => ['203.0.113.77', '203.0.113.x'],
    'ipv6' => ['2001:db8:abcd:12:34::1', '2001:db8:abcd::/48'],
    'loopback v6' => ['::1', '::/48'],
    'not an ip' => ['localhost', 'hidden'],
    'null' => [null, null],
]);

function securityAuthLogs(string $event)
{
    return Activity::query()->where('log_name', 'auth')->where('event', $event);
}
