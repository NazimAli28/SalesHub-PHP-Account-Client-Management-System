<?php

use App\Http\Controllers\Analytics\OverviewController;
use App\Support\ApiRateLimits;
use Illuminate\Support\Facades\Route;

Route::get('analytics/overview', OverviewController::class)
    ->middleware('throttle:'.ApiRateLimits::ANALYTICS)
    ->name('analytics.overview');
