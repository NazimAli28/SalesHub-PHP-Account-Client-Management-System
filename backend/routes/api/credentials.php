<?php

use App\Http\Controllers\PlatformAccountRevealController;
use App\Http\Controllers\SocialAccountRevealController;
use Illuminate\Support\Facades\Route;

Route::post('platform-accounts/{platformAccount}/reveal', PlatformAccountRevealController::class)
    ->middleware('throttle:20,1')
    ->name('platform-accounts.reveal');

Route::post('social-accounts/{socialAccount}/reveal', SocialAccountRevealController::class)
    ->middleware('throttle:20,1')
    ->name('social-accounts.reveal');
