<?php

use App\Http\Controllers\Workstations\WorkstationController;
use Illuminate\Support\Facades\Route;

Route::apiResource('workstations', WorkstationController::class);
