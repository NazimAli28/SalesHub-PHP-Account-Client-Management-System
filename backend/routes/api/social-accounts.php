<?php

use App\Http\Controllers\SocialAccounts\SocialAccountController;
use Illuminate\Support\Facades\Route;

// The in-use flag is an ordinary field of PATCH /social-accounts/{id} (update-vs-queue); the matrix has no separate toggle permission.
Route::apiResource('social-accounts', SocialAccountController::class)->parameters(['social-accounts' => 'socialAccount']);
