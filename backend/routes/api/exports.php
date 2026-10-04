<?php

use App\Http\Controllers\Exports\ExportController;
use Illuminate\Support\Facades\Route;

Route::get('exports/{type}', ExportController::class)->name('exports.show');
