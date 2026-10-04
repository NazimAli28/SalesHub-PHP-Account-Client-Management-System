<?php

namespace App\Support;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Rate limits for POST /api/auth/login (the `login` named limiter) and the two-factor challenge.
 */
final class LoginThrottle
{
    public const NAME = 'login';

    private const TWO_FACTOR_ATTEMPTS = 5;

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

    /**
     * Two-factor challenge: 5 wrong codes per minute for one pending user and IP. Applied inside
     * the controller because the pending user is only known from the session.
     *
     * @return int|null seconds until the next attempt is allowed, or null when not locked out
     */
    public static function twoFactorLockedOutFor(int|string $userId, ?string $ip): ?int
    {
        $key = self::twoFactorKey($userId, $ip);

        return RateLimiter::tooManyAttempts($key, self::TWO_FACTOR_ATTEMPTS)
            ? max(RateLimiter::availableIn($key), 1)
            : null;
    }

    public static function twoFactorFailed(int|string $userId, ?string $ip): void
    {
        RateLimiter::hit(self::twoFactorKey($userId, $ip), 60);
    }

    public static function twoFactorClear(int|string $userId, ?string $ip): void
    {
        RateLimiter::clear(self::twoFactorKey($userId, $ip));
    }

    public static function twoFactorLockedOutResponse(int $retryAfter): JsonResponse
    {
        return response()->json([
            'message' => "Too many verification attempts. Please try again in {$retryAfter} seconds.",
            'code' => 'too_many_attempts',
            'retry_after' => $retryAfter,
        ], 429, ['Retry-After' => (string) $retryAfter]);
    }

    private static function twoFactorKey(int|string $userId, ?string $ip): string
    {
        return 'two-factor-challenge:'.$userId.'|'.$ip;
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
