<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    // Comma-separated origins allowed to call the API with cookies. In production the SPA is served by
    // the same origin, so FRONTEND_URL is usually APP_URL; there is no localhost fallback there.
    'allowed_origins' => array_values(array_filter(array_map('trim', explode(',', (string) env(
        'FRONTEND_URL',
        env('APP_ENV', 'production') === 'production' ? '' : 'http://localhost:5173',
    ))))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,

];
