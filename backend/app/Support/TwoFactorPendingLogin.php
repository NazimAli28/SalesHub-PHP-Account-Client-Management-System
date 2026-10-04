<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Http\Request;

/**
 * A sign-in that passed the password check and now waits for a TOTP or recovery code.
 * Lives in the (not yet authenticated) session for a few minutes.
 */
final class TwoFactorPendingLogin
{
    public const TTL_SECONDS = 300;

    private const KEY = 'login.two_factor';

    public static function start(Request $request, User $user, bool $remember): void
    {
        $request->session()->put(self::KEY, [
            'id' => $user->getKey(),
            'remember' => $remember,
            'at' => now()->getTimestamp(),
        ]);
    }

    /**
     * The pending user, or null when there is none, it expired, or the user is gone.
     */
    public static function user(Request $request): ?User
    {
        $pending = $request->session()->get(self::KEY);

        if (! is_array($pending) || ! isset($pending['id'], $pending['at'])) {
            return null;
        }

        if (now()->getTimestamp() - (int) $pending['at'] > self::TTL_SECONDS) {
            self::forget($request);

            return null;
        }

        return User::query()->find($pending['id']);
    }

    public static function remember(Request $request): bool
    {
        $pending = $request->session()->get(self::KEY);

        return is_array($pending) && ($pending['remember'] ?? false) === true;
    }

    public static function forget(Request $request): void
    {
        $request->session()->forget(self::KEY);
    }
}
