<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Office-network allowlist
    |--------------------------------------------------------------------------
    |
    | When enabled, users holding one of the listed roles may only sign in and
    | use the API from the given IPs or CIDR ranges. Admin and support users are
    | never restricted. Disabled by default (the public demo keeps it off).
    |
    */

    'ip_allowlist' => [
        'enabled' => (bool) env('IP_ALLOWLIST_ENABLED', false),
        'ranges' => array_values(array_filter(array_map('trim', explode(',', (string) env('IP_ALLOWLIST', '127.0.0.1,::1'))))),
        'roles' => ['sales_executive', 'team_lead'],
    ],

    /*
    | Whether the generated API reference at /docs/api is viewable outside the local environment.
    | It documents endpoints only; every endpoint still requires authentication.
    */
    'public_api_docs' => (bool) env('PUBLIC_API_DOCS', true),

];
