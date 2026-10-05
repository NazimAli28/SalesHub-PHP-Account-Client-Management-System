<?php

use App\Http\Controllers\Imports\ImportController;
use App\Http\Controllers\Imports\ImportPreviewController;
use App\Http\Controllers\Imports\ImportStartController;
use App\Support\ApiRateLimits;
use Illuminate\Support\Facades\Route;

Route::get('imports/templates/{type}', [ImportController::class, 'template'])->name('imports.template');
Route::get('imports', [ImportController::class, 'index'])->name('imports.index');
Route::get('imports/{import}', [ImportController::class, 'show'])->whereNumber('import')->name('imports.show');
Route::get('imports/{import}/errors', [ImportController::class, 'errors'])->whereNumber('import')->name('imports.errors');

// Uploading, previewing and starting parse CSVs and run validation: 10 per minute per user, together.
Route::middleware('throttle:'.ApiRateLimits::IMPORTS)->group(function () {
    Route::post('imports', [ImportController::class, 'store'])->name('imports.store');
    Route::post('imports/{import}/preview', ImportPreviewController::class)->whereNumber('import')->name('imports.preview');
    Route::post('imports/{import}/start', ImportStartController::class)->whereNumber('import')->name('imports.start');
});
