<?php

use App\Http\Controllers\SpaController;
use Illuminate\Support\Facades\Route;

// The built single-page app (production: one origin for the SPA and the API). Every GET that is not
// the API, the CSRF cookie, the API reference or the health check returns index.html; the client
// router decides what to show. `web` middleware is skipped on purpose: the shell needs no session
// or cookies, and crawlers must not create session rows.
Route::get('/{path?}', SpaController::class)
    ->where('path', '^(?!(api|sanctum|docs|up)(/|$)).*$')
    ->withoutMiddleware('web')
    ->name('spa');
