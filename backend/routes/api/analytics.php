<?php

use App\Http\Controllers\Analytics\OverviewController;
use Illuminate\Support\Facades\Route;

Route::get('analytics/overview', OverviewController::class)->name('analytics.overview');
