<?php

use App\Http\Controllers\Exports\ExportController;
use App\Support\ApiRateLimits;
use Illuminate\Support\Facades\Route;

Route::get('exports/{type}', ExportController::class)
    ->middleware('throttle:'.ApiRateLimits::EXPORTS)
    ->name('exports.show');
