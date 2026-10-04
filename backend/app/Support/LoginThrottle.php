<?php

namespace App\Support;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Rate limits for POST /api/auth/login (the `login` named limiter).
 */
final class LoginThrottle
{
    public const NAME = 'login';

    /**
     * @return list<Limit>
     */
    public static function limits(Request $request): array
    {
        $login = Str::lower(trim((string) $request->input('login')));
        $ip = (string) $request->ip();

        return [
            Limit::perMinute(5)->by(self::loginKey($login, $ip))->response(self::lockedOut(...)),
            Limit::perMinute(20)->by($ip)->response(self::lockedOut(...)),
            Limit::perHour(30)->by($login)->response(self::lockedOut(...)),
        ];
    }

    /**
     * Forget the per-minute counter for this login and IP (after a successful sign-in).
     */
    public static function clear(string $login, ?string $ip): void
    {
        RateLimiter::clear(md5(self::NAME.self::loginKey($login, (string) $ip)));
    }

    private static function loginKey(string $login, string $ip): string
    {
        return $login.'|'.$ip;
    }

    /**
     * @param  array<string, mixed>  $headers
     */
    private static function lockedOut(Request $request, array $headers): JsonResponse
    {
        $retryAfter = (int) ($headers['Retry-After'] ?? 60);

        // One audit entry per lockout window rather than one per blocked request.
        $marker = 'login-lockout-logged:'.md5(Str::lower((string) $request->input('login')).'|'.$request->ip());

        if (Cache::add($marker, true, max($retryAfter, 1))) {
            AuditLogger::auth('login_locked_out', null, $request, [
                'identifier' => Str::limit((string) $request->input('login'), 255, ''),
            ]);
        }

        return response()->json([
            'message' => "Too many login attempts. Please try again in {$retryAfter} seconds.",
            'code' => 'too_many_attempts',
            'retry_after' => $retryAfter,
        ], 429, $headers);
    }
}
