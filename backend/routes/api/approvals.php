<?php

use App\Http\Controllers\Approvals\AccountRequestController;
use App\Http\Controllers\Approvals\ApprovalDecisionController;
use App\Http\Controllers\Approvals\ApprovalRequestController;
use Illuminate\Support\Facades\Route;

Route::prefix('approvals')->name('approvals.')->group(function () {
    Route::get('/', [ApprovalRequestController::class, 'index'])->name('index');
    Route::get('pending-count', [ApprovalRequestController::class, 'pendingCount'])->name('pending-count');
    Route::get('{approval}', [ApprovalRequestController::class, 'show'])->whereNumber('approval')->name('show');
    Route::post('{approval}/approve', [ApprovalDecisionController::class, 'approve'])->whereNumber('approval')->name('approve');
    Route::post('{approval}/reject', [ApprovalDecisionController::class, 'reject'])->whereNumber('approval')->name('reject');
    Route::post('{approval}/cancel', [ApprovalDecisionController::class, 'cancel'])->whereNumber('approval')->name('cancel');
});

// A create-style request with no target record (action `request_accounts`); lives here because it only queues an approval.
Route::post('platform-accounts/request-new', AccountRequestController::class)->name('platform-accounts.request-new');
