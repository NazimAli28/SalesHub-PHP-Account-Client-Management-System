<?php

namespace App\Actions\Auth;

use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * The public demo shares its accounts between visitors, so nobody may lock them
 * (by changing the password or turning on two-factor sign-in).
 */
final class EnsureNotDemoMode
{
    public static function enabled(): bool
    {
        return (bool) config('saleshub.demo_mode');
    }

    /**
     * @throws HttpResponseException 403 when demo mode is on
     */
    public static function check(string $action): void
    {
        if (! self::enabled()) {
            return;
        }

        throw self::exception("{$action} is disabled");
    }

    /**
     * The 403 `demo_mode` response; `$reason` completes "The public demo shares its accounts, so ...".
     */
    public static function exception(string $reason): HttpResponseException
    {
        return new HttpResponseException(response()->json([
            'message' => "The public demo shares its accounts, so {$reason}.",
            'code' => 'demo_mode',
        ], 403));
    }
}
