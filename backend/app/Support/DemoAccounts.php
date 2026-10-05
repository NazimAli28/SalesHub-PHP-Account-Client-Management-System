<?php

namespace App\Support;

use App\Actions\Auth\EnsureNotDemoMode;
use App\Models\User;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;

/**
 * The seeded accounts of the public demo (`saleshub.demo_usernames`). Every visitor shares them, so in
 * demo mode nobody may change what is needed to sign in to them or switch them off.
 */
final class DemoAccounts
{
    /**
     * @return list<string>
     */
    public static function usernames(): array
    {
        /** @var list<string> $usernames */
        $usernames = (array) config('saleshub.demo_usernames', []);

        return $usernames;
    }

    /**
     * Demo mode is on and the user is one of the seeded demo accounts.
     */
    public static function isProtected(User $user): bool
    {
        return EnsureNotDemoMode::enabled()
            && in_array(strtolower((string) $user->username), self::usernames(), true);
    }

    /**
     * Demo mode is on and the sign-in identifier (username or email, already lowercased) belongs to a
     * seeded demo account.
     */
    public static function isProtectedIdentifier(string $identifier): bool
    {
        if (! EnsureNotDemoMode::enabled() || $identifier === '') {
            return false;
        }

        if (in_array($identifier, self::usernames(), true)) {
            return true;
        }

        return str_contains($identifier, '@') && User::query()
            ->where(DB::raw('lower(email)'), $identifier)
            ->whereIn('username', self::usernames())
            ->exists();
    }

    /**
     * @param  string  $reason  completes "The public demo shares its accounts, so ..."
     *
     * @throws HttpResponseException 403 `demo_mode` when the user is a protected demo account
     */
    public static function guard(User $user, string $reason): void
    {
        if (self::isProtected($user)) {
            throw EnsureNotDemoMode::exception($reason);
        }
    }
}
