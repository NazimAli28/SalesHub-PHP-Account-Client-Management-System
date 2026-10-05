<?php

namespace App\Support;

/**
 * "Today" for date-only fields picked in the user's browser.
 *
 * The API runs in UTC, but a user east of UTC is already on tomorrow's date just after their
 * midnight. Validating "not in the future" against the latest calendar date anywhere (UTC+14)
 * accepts every valid local date while still rejecting genuinely future ones.
 */
final class LocalToday
{
    public static function latest(): string
    {
        return now('Etc/GMT-14')->toDateString();
    }

    /** Validation rule: the date is not later than today in any timezone. */
    public static function notFuture(): string
    {
        return 'before_or_equal:'.self::latest();
    }
}
