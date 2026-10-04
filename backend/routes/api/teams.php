<?php

use App\Http\Controllers\Teams\TeamController;
use Illuminate\Support\Facades\Route;

Route::apiResource('teams', TeamController::class);
