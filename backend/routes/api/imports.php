<?php

use App\Http\Controllers\Imports\ImportController;
use App\Http\Controllers\Imports\ImportPreviewController;
use App\Http\Controllers\Imports\ImportStartController;
use Illuminate\Support\Facades\Route;

Route::get('imports/templates/{type}', [ImportController::class, 'template'])->name('imports.template');
Route::get('imports', [ImportController::class, 'index'])->name('imports.index');
Route::post('imports', [ImportController::class, 'store'])->name('imports.store');
Route::get('imports/{import}', [ImportController::class, 'show'])->whereNumber('import')->name('imports.show');
Route::post('imports/{import}/preview', ImportPreviewController::class)->whereNumber('import')->name('imports.preview');
Route::post('imports/{import}/start', ImportStartController::class)->whereNumber('import')->name('imports.start');
Route::get('imports/{import}/errors', [ImportController::class, 'errors'])->whereNumber('import')->name('imports.errors');
