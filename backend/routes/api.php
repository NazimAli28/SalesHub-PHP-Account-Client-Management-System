<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\Auth\MeController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\Auth\SessionsController;
use App\Http\Controllers\Auth\TwoFactorChallengeController;
use App\Http\Controllers\Auth\TwoFactorController;
use App\Support\LoginThrottle;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    Route::post('login', LoginController::class)->middleware('throttle:'.LoginThrottle::NAME);
    // Per-user limits for wrong codes live in the controller; this caps requests per IP.
    Route::post('two-factor-challenge', TwoFactorChallengeController::class)->middleware('throttle:20,1');
    Route::post('logout', LogoutController::class)->middleware('auth:sanctum');

    Route::middleware(['auth:sanctum', 'active', 'ip.allowed'])->group(function () {
        Route::get('me', MeController::class);
        Route::put('password', PasswordController::class)->middleware('throttle:6,1');

        Route::controller(TwoFactorController::class)->prefix('two-factor')->middleware('throttle:10,1')->group(function () {
            Route::post('/', 'store');
            Route::post('confirm', 'confirm');
            Route::delete('/', 'destroy');
            Route::post('recovery-codes/view', 'recoveryCodes');
            Route::post('recovery-codes', 'regenerateRecoveryCodes');
        });

        Route::get('sessions', [SessionsController::class, 'index']);
        Route::delete('sessions/others', [SessionsController::class, 'destroyOthers'])->middleware('throttle:6,1');
    });
});

/*
| Module routes. Every file in routes/api/ is loaded inside the authenticated group, in name order.
| Each module owns exactly one file (routes/api/leads.php, routes/api/orders.php, ...).
*/
Route::middleware(['auth:sanctum', 'active', 'ip.allowed'])->group(function () {
    $files = glob(__DIR__.'/api/*.php') ?: [];
    sort($files);

    foreach ($files as $file) {
        require $file;
    }
});
