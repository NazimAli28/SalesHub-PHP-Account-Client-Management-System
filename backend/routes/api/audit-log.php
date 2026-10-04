<?php

use App\Http\Controllers\AuditLog\AuditLogController;
use Illuminate\Support\Facades\Route;

Route::get('audit-log', AuditLogController::class)->name('audit-log.index');
