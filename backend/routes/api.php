<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\Auth\MeController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\PlatformAccountRevealController;
use App\Http\Controllers\SocialAccountRevealController;
use App\Support\LoginThrottle;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    Route::post('login', LoginController::class)->middleware('throttle:'.LoginThrottle::NAME);
    Route::post('logout', LogoutController::class)->middleware('auth:sanctum');

    Route::middleware(['auth:sanctum', 'active', 'ip.allowed'])->group(function () {
        Route::get('me', MeController::class);
        Route::put('password', PasswordController::class)->middleware('throttle:6,1');
    });
});

Route::middleware(['auth:sanctum', 'active', 'ip.allowed'])->group(function () {
    Route::post('platform-accounts/{platformAccount}/reveal', PlatformAccountRevealController::class)
        ->middleware('throttle:20,1');
    Route::post('social-accounts/{socialAccount}/reveal', SocialAccountRevealController::class)
        ->middleware('throttle:20,1');
});
