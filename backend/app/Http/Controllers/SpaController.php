<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;

/**
 * Serves the built React app's index.html for client-side routes, through Laravel so the HTML
 * security headers (SecurityHeaders middleware) apply. Hashed assets are served by the web server.
 */
class SpaController extends Controller
{
    private const PLACEHOLDER = '<!doctype html><html lang="en"><head><meta charset="UTF-8">'
        .'<meta name="viewport" content="width=device-width, initial-scale=1.0"><title>SalesHub</title></head>'
        .'<body><h1>SalesHub</h1><p>The web app has not been built. Run <code>npm run build</code> in '
        .'<code>frontend/</code> or use the Docker image; the API is available under <code>/api</code>.</p></body></html>';

    public function __invoke(?string $path = null): Response
    {
        // A missing file (script, image, map) must not turn into HTML.
        if ($path !== null && str_contains(basename($path), '.')) {
            abort(404);
        }

        $file = (string) config('saleshub.spa_index');
        $html = is_file($file) ? (string) file_get_contents($file) : self::PLACEHOLDER;

        return response($html, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'no-cache',
        ]);
    }
}
