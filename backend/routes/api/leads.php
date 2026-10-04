<?php

use App\Http\Controllers\Leads\LeadController;
use App\Http\Controllers\Leads\LeadOwnerController;
use App\Http\Controllers\Leads\LeadStageController;
use Illuminate\Support\Facades\Route;

Route::apiResource('leads', LeadController::class);
Route::patch('leads/{lead}/stage', LeadStageController::class)->name('leads.stage');
Route::patch('leads/{lead}/owner', LeadOwnerController::class)->name('leads.owner');
