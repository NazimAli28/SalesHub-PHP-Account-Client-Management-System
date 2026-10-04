<?php

use App\Http\Controllers\PlatformAccounts\PlatformAccountAssignController;
use App\Http\Controllers\PlatformAccounts\PlatformAccountController;
use App\Http\Controllers\PlatformAccounts\PlatformAccountStandingController;
use Illuminate\Support\Facades\Route;

Route::apiResource('platform-accounts', PlatformAccountController::class)->parameters(['platform-accounts' => 'platformAccount']);
Route::patch('platform-accounts/{platformAccount}/assign', PlatformAccountAssignController::class)->name('platform-accounts.assign');
Route::patch('platform-accounts/{platformAccount}/standing', PlatformAccountStandingController::class)->name('platform-accounts.standing');
