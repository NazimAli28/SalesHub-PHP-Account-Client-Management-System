<?php

use App\Http\Controllers\Orders\OrderController;
use App\Http\Controllers\Orders\OrderItemController;
use Illuminate\Support\Facades\Route;

Route::apiResource('orders', OrderController::class);

// Item edits return the parent order so the caller sees the recalculated totals.
Route::apiResource('orders.items', OrderItemController::class)->only(['store', 'update', 'destroy'])->scoped();
