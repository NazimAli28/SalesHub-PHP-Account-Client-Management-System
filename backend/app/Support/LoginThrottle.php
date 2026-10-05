<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rate limits for POST /api/auth/login (the `login` named limiter) and the two-factor challenge.
 *
 * Login limits (keys are prefixed so a login name can never collide with an IP key):
 * - `login:{login}|{ip}`   5 failed attempts per minute for one login from one IP;
 * - `login-ip:{ip}`        20 attempts per minute from one IP, whatever the outcome;
 * - `login-hour:{login}`   30 failed attempts per hour for one login from anywhere. Skipped for the
 *                          seeded demo accounts in demo mode, so strangers cannot lock them for an hour.
 *
 * Only failed attempts (422) count toward the per-login limits, so signing in successfully never
 * locks an account.
 */
final class LoginThrottle
{
    public const NAME = 'login';

    /** Wrong two-factor codes per minute for one pending user and IP. */
    private const TWO_FACTOR_ATTEMPTS = 5;

    /** Wrong two-factor codes per user (from any IP) within {@see TWO_FACTOR_USER_DECAY} seconds. */
    private const TWO_FACTOR_USER_ATTEMPTS = 10;

    private const TWO_FACTOR_USER_DECAY = 900;

    /**
     * @return list<Limit>
     */
    public static function limits(Request $request): array
    {
        $login = self::normalize($request->input('login'));
        $ip = (string) $request->ip();
        $failed = fn (Response $response): bool => $response->getStatusCode() === 422;

        $limits = [
            Limit::perMinute(5)->by(self::loginKey($login, $ip))->after($failed)->response(self::lockedOut(...)),
            Limit::perMinute(20)->by('login-ip:'.$ip)->response(self::lockedOut(...)),
        ];

        if (! DemoAccounts::isProtectedIdentifier($login)) {
            $limits[] = Limit::perHour(30)->by('login-hour:'.$login)->after($failed)->response(self::lockedOut(...));
        }

        return $limits;
    }

    /**
     * Forget the per-minute failure counter for this login and IP (after a successful sign-in).
     */
    public static function clear(string $login, ?string $ip): void
    {
        RateLimiter::clear(md5(self::NAME.self::loginKey(self::normalize($login), (string) $ip)));
    }

    /**
     * Two-factor challenge (and turning two-factor off): 5 wrong codes per minute for one user and IP,
     * and 10 per 15 minutes for one user from any IP. Applied inside the controllers because the user
     * is only known there.
     *
     * @return int|null seconds until the next attempt is allowed, or null when not locked out
     */
    public static function twoFactorLockedOutFor(int|string $userId, ?string $ip): ?int
    {
        $limits = [
            self::twoFactorKey($userId, $ip) => self::TWO_FACTOR_ATTEMPTS,
            self::twoFactorUserKey($userId) => self::TWO_FACTOR_USER_ATTEMPTS,
        ];

        $retryAfter = null;

        foreach ($limits as $key => $maxAttempts) {
            if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
                $retryAfter = max($retryAfter ?? 0, RateLimiter::availableIn($key), 1);
            }
        }

        return $retryAfter;
    }

    public static function twoFactorFailed(int|string $userId, ?string $ip): void
    {
        RateLimiter::hit(self::twoFactorKey($userId, $ip), 60);
        RateLimiter::hit(self::twoFactorUserKey($userId), self::TWO_FACTOR_USER_DECAY);
    }

    public static function twoFactorClear(int|string $userId, ?string $ip): void
    {
        RateLimiter::clear(self::twoFactorKey($userId, $ip));
        RateLimiter::clear(self::twoFactorUserKey($userId));
    }

    public static function twoFactorLockedOutResponse(int $retryAfter): JsonResponse
    {
        return response()->json([
            'message' => "Too many verification attempts. Please try again in {$retryAfter} seconds.",
            'code' => 'too_many_attempts',
            'retry_after' => $retryAfter,
        ], 429, ['Retry-After' => (string) $retryAfter]);
    }

    /**
     * What the audit log may record about a sign-in identifier: the identifier when it belongs to an
     * existing user, otherwise nothing (a mistyped value could be a password typed into the wrong box).
     *
     * @return array{identifier?: string}
     */
    public static function auditIdentifier(string $identifier, ?User $user = null): array
    {
        $identifier = self::normalize($identifier);

        if ($identifier === '') {
            return [];
        }

        $known = $user !== null || User::query()
            ->where(DB::raw('lower(username)'), $identifier)
            ->orWhere(DB::raw('lower(email)'), $identifier)
            ->exists();

        return $known ? ['identifier' => Str::limit($identifier, 255, '')] : [];
    }

    private static function normalize(mixed $login): string
    {
        return Str::lower(trim(is_string($login) ? $login : ''));
    }

    private static function twoFactorKey(int|string $userId, ?string $ip): string
    {
        return 'two-factor-challenge:'.$userId.'|'.$ip;
    }

    private static function twoFactorUserKey(int|string $userId): string
    {
        return 'two-factor-user:'.$userId;
    }

    private static function loginKey(string $login, string $ip): string
    {
        return 'login:'.$login.'|'.$ip;
    }

    /**
     * @param  array<string, mixed>  $headers
     */
    private static function lockedOut(Request $request, array $headers): JsonResponse
    {
        $retryAfter = (int) ($headers['Retry-After'] ?? 60);
        $login = self::normalize($request->input('login'));

        // One audit entry per lockout window rather than one per blocked request.
        $marker = 'login-lockout-logged:'.md5($login.'|'.$request->ip());

        if (Cache::add($marker, true, max($retryAfter, 1))) {
            AuditLogger::auth('login_locked_out', null, $request, self::auditIdentifier($login));
        }

        return response()->json([
            'message' => "Too many login attempts. Please try again in {$retryAfter} seconds.",
            'code' => 'too_many_attempts',
            'retry_after' => $retryAfter,
        ], 429, $headers);
    }
}
