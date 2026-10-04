<?php

namespace App\Support;

use App\Models\User;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Office-network restriction (config/saleshub.php `ip_allowlist`).
 */
final class IpAllowlist
{
    public static function permits(User $user, ?string $ip): bool
    {
        /** @var array{enabled: bool, ranges: list<string>, roles: list<string>} $config */
        $config = config('saleshub.ip_allowlist');

        if (! $config['enabled'] || ! $user->hasAnyRole($config['roles'])) {
            return true;
        }

        return $ip !== null && IpUtils::checkIp($ip, $config['ranges']);
    }
}
