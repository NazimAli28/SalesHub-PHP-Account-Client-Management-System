<?php

namespace App\Support;

use App\Actions\Auth\EnsureNotDemoMode;

/**
 * Coarsens IP addresses for display in the public demo, where everyone shares the same accounts and
 * would otherwise see each other's addresses: IPv4 keeps its /24 ("203.0.113.x"), IPv6 its /48.
 */
final class IpMask
{
    /** Property keys in audit-log entries that hold an IP address. */
    private const IP_KEYS = ['ip', 'ip_address', 'last_login_ip'];

    public static function mask(?string $ip): ?string
    {
        if ($ip === null || $ip === '') {
            return $ip;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $parts = explode('.', $ip);

            return "{$parts[0]}.{$parts[1]}.{$parts[2]}.x";
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            $packed = inet_pton($ip);

            if ($packed !== false) {
                $network = inet_ntop(substr($packed, 0, 6).str_repeat("\0", 10));

                return $network === false ? 'hidden' : $network.'/48';
            }
        }

        return 'hidden';
    }

    /**
     * Masks only in demo mode; elsewhere admins see the real address.
     */
    public static function forDisplay(?string $ip): ?string
    {
        return EnsureNotDemoMode::enabled() ? self::mask($ip) : $ip;
    }

    /**
     * Masks every IP-looking property (any depth) of an audit-log entry, in demo mode only.
     *
     * @param  array<mixed>  $values
     * @return array<mixed>
     */
    public static function maskProperties(array $values): array
    {
        if (! EnsureNotDemoMode::enabled()) {
            return $values;
        }

        foreach ($values as $key => $value) {
            if (is_array($value)) {
                $values[$key] = self::maskProperties($value);
            } elseif (in_array($key, self::IP_KEYS, true) && (is_string($value) || $value === null)) {
                $values[$key] = self::mask($value);
            }
        }

        return $values;
    }
}
