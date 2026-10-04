<?php

use App\Http\Controllers\Payments\MarkPaymentPaidController;
use App\Http\Controllers\Payments\OrderPaymentController;
use App\Http\Controllers\Payments\PaymentController;
use Illuminate\Support\Facades\Route;

// Installments of one order.
Route::get('orders/{order}/payments', [OrderPaymentController::class, 'index'])->name('orders.payments.index');
Route::post('orders/{order}/payments', [OrderPaymentController::class, 'store'])->name('orders.payments.store');

// Global due / overdue list and single-payment endpoints (payments are created through their order).
Route::apiResource('payments', PaymentController::class)->except('store');
Route::post('payments/{payment}/mark-paid', MarkPaymentPaidController::class)->name('payments.mark-paid');
