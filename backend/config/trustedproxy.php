<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Trusted proxies
    |--------------------------------------------------------------------------
    |
    | Comma-separated IPs or CIDR ranges of the reverse proxy / load balancer in front of the app
    | (Laravel's TrustProxies middleware reads this when no proxies are set in bootstrap/app.php).
    | Only requests from these addresses may set X-Forwarded-For/-Proto, which decide the client IP
    | (rate limits, audit log, IP allowlist) and whether the request counts as HTTPS.
    |
    | Never use "*" unless the proxy is the only thing that can reach the app: otherwise any client
    | can spoof its IP address. Empty means no proxy is trusted.
    |
    */

    'proxies' => env('TRUSTED_PROXIES') ?: null,

];
