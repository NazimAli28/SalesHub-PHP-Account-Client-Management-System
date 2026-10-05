<?php

namespace App\Support;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Named rate limiters for the expensive endpoints, keyed by the signed-in user (by IP as a fallback).
 * Exceeding one answers 429 with Retry-After.
 */
final class ApiRateLimits
{
    /** GET /api/analytics/overview: 30 per minute. */
    public const ANALYTICS = 'analytics';

    /** GET /api/exports/{type}: 5 per minute. */
    public const EXPORTS = 'exports';

    /** POST /api/imports, /imports/{id}/preview and /imports/{id}/start: 10 per minute together. */
    public const IMPORTS = 'imports';

    public static function register(): void
    {
        RateLimiter::for(self::ANALYTICS, fn (Request $request): Limit => Limit::perMinute(30)->by(self::key(self::ANALYTICS, $request)));
        RateLimiter::for(self::EXPORTS, fn (Request $request): Limit => Limit::perMinute(5)->by(self::key(self::EXPORTS, $request)));
        RateLimiter::for(self::IMPORTS, fn (Request $request): Limit => Limit::perMinute(10)->by(self::key(self::IMPORTS, $request)));
    }

    private static function key(string $name, Request $request): string
    {
        $user = $request->user();

        return $name.':'.($user !== null ? 'user:'.$user->getAuthIdentifier() : 'ip:'.$request->ip());
    }
}
