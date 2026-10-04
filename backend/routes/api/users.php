<?php

use App\Http\Controllers\Users\UserActivationController;
use App\Http\Controllers\Users\UserController;
use Illuminate\Support\Facades\Route;

Route::apiResource('users', UserController::class);
Route::patch('users/{user}/deactivate', [UserActivationController::class, 'deactivate'])->name('users.deactivate');
Route::patch('users/{user}/activate', [UserActivationController::class, 'activate'])->name('users.activate');
