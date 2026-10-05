<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Security headers on every response, by kind of request:
 *
 * - API, CSV downloads and the CSRF cookie (`api/*`, `sanctum/*`): nothing may render or embed them;
 *   never cached.
 * - API reference (`docs/*`): its own CSP for the Stoplight Elements viewer (pinned unpkg files with
 *   SRI, inline scripts only with this request's nonce).
 * - Everything else is HTML, in production the SPA's index.html: a strict same-origin CSP.
 *
 * A header the route already set is left alone. HSTS is sent on HTTPS requests outside local.
 */
class SecurityHeaders
{
    public const API_CSP = "default-src 'none'; frame-ancestors 'none'";

    public const APP_CSP = "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; "
        ."img-src 'self' data: blob:; font-src 'self'; connect-src 'self'; worker-src 'self' blob:; "
        ."manifest-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; "
        ."frame-ancestors 'none'; upgrade-insecure-requests";

    public const PERMISSIONS_POLICY = 'camera=(), microphone=(), geolocation=(), payment=(), usb=(), browsing-topics=()';

    public const HSTS = 'max-age=31536000; includeSubDomains';

    public function handle(Request $request, Closure $next): Response
    {
        $kind = match (true) {
            $request->is('api', 'api/*', 'sanctum/*') => 'api',
            $request->is('docs', 'docs/*') => 'docs',
            default => 'app',
        };

        // The docs view prints this nonce on its inline scripts (Vite::cspNonce()).
        $nonce = $kind === 'docs' ? Vite::useCspNonce() : null;

        $response = $next($request);

        $headers = [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            ...match ($kind) {
                'api' => [
                    'Content-Security-Policy' => self::API_CSP,
                    'Referrer-Policy' => 'no-referrer',
                ],
                'docs' => [
                    'Content-Security-Policy' => self::docsCsp((string) $nonce),
                    'Referrer-Policy' => 'strict-origin-when-cross-origin',
                    'Cross-Origin-Opener-Policy' => 'same-origin',
                    'Permissions-Policy' => self::PERMISSIONS_POLICY,
                ],
                default => [
                    'Content-Security-Policy' => self::APP_CSP,
                    'Referrer-Policy' => 'strict-origin-when-cross-origin',
                    'Permissions-Policy' => self::PERMISSIONS_POLICY,
                    'Cross-Origin-Opener-Policy' => 'same-origin',
                    'Cross-Origin-Resource-Policy' => 'same-origin',
                ],
            },
        ];

        if ($request->isSecure() && ! app()->environment('local')) {
            $headers['Strict-Transport-Security'] = self::HSTS;
        }

        foreach ($headers as $name => $value) {
            if (! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }

        // API data (and CSV exports) must not linger in browser or proxy caches.
        if ($kind === 'api') {
            $response->headers->set('Cache-Control', 'no-store, private');
        }

        return $response;
    }

    /**
     * Stoplight Elements comes from unpkg (exact versions, SRI-pinned in the view) and injects its
     * own styles. Inline scripts run only with the per-request nonce.
     */
    public static function docsCsp(string $nonce): string
    {
        return "default-src 'none'; "
            ."script-src 'self' 'nonce-{$nonce}' https://unpkg.com; "
            ."style-src 'self' 'unsafe-inline' https://unpkg.com; "
            ."img-src 'self' data: https:; font-src 'self' data: https://unpkg.com; "
            ."connect-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'";
    }
}
