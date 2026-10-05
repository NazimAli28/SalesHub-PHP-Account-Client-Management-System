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

    /*
    | Public demo mode. The demo accounts are shared by every visitor, so changing a password
    | and turning on two-factor sign-in are refused (403) to keep them usable for everyone.
    */
    'demo_mode' => (bool) env('DEMO_MODE', false),

    /*
    | The seeded demo accounts (usernames). In demo mode their password, username, email and role
    | cannot be changed, they cannot be deactivated, activated or deleted, and they are never locked
    | out of sign-in for an hour. Users created during the demo stay fully editable.
    */
    'demo_usernames' => array_values(array_filter(array_map(
        fn (string $username): string => strtolower(trim($username)),
        explode(',', (string) env(
            'DEMO_USERNAMES',
            'admin,support,support2,tl,tl2,tl3,tl4,agent1,agent2,agent3,agent4,agent5,agent6,agent7',
        )),
    ))),

    /*
    | Uploaded CSVs that were never started are deleted (with their import row) after this many hours.
    */
    'imports' => [
        'prune_unstarted_after_hours' => (int) env('IMPORTS_PRUNE_AFTER_HOURS', 24),
    ],

];
