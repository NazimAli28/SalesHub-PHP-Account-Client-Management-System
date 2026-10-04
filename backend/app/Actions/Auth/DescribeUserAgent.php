<?php

namespace App\Actions\Auth;

/**
 * A short "Browser on OS" label from a User-Agent header, good enough for a sessions list.
 */
final class DescribeUserAgent
{
    /** @var array<string, string> pattern => label, first match wins (order matters: Edge and Opera also say Chrome) */
    private const BROWSERS = [
        '/Edg(e|A|iOS)?\//' => 'Edge',
        '/OPR\/|Opera/' => 'Opera',
        '/SamsungBrowser\//' => 'Samsung Internet',
        '/Firefox\/|FxiOS\//' => 'Firefox',
        '/Chrome\/|CriOS\//' => 'Chrome',
        '/Safari\//' => 'Safari',
        '/curl\//i' => 'curl',
    ];

    /** @var array<string, string> */
    private const PLATFORMS = [
        '/iPhone|iPad|iPod/' => 'iOS',
        '/Android/' => 'Android',
        '/Windows/' => 'Windows',
        '/Mac OS X|Macintosh/' => 'macOS',
        '/CrOS/' => 'ChromeOS',
        '/Linux/' => 'Linux',
    ];

    public static function label(?string $userAgent): string
    {
        if ($userAgent === null || trim($userAgent) === '') {
            return 'Unknown device';
        }

        $browser = self::match(self::BROWSERS, $userAgent);
        $platform = self::match(self::PLATFORMS, $userAgent);

        return match (true) {
            $browser !== null && $platform !== null => "{$browser} on {$platform}",
            $browser !== null => $browser,
            $platform !== null => $platform,
            default => 'Unknown device',
        };
    }

    /**
     * @param  array<string, string>  $patterns
     */
    private static function match(array $patterns, string $userAgent): ?string
    {
        foreach ($patterns as $pattern => $label) {
            if (preg_match($pattern, $userAgent) === 1) {
                return $label;
            }
        }

        return null;
    }
}
