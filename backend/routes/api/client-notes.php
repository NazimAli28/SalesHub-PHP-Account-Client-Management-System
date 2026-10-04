<?php

use App\Http\Controllers\ClientNotes\ClientNoteController;
use App\Http\Controllers\ClientNotes\ClientTimelineController;
use Illuminate\Support\Facades\Route;

Route::scopeBindings()->group(function () {
    Route::get('clients/{client}/notes', [ClientNoteController::class, 'index'])->name('clients.notes.index');
    Route::post('clients/{client}/notes', [ClientNoteController::class, 'store'])->name('clients.notes.store');
    Route::patch('clients/{client}/notes/{note}', [ClientNoteController::class, 'update'])->name('clients.notes.update');
    Route::delete('clients/{client}/notes/{note}', [ClientNoteController::class, 'destroy'])->name('clients.notes.destroy');
});

Route::get('clients/{client}/timeline', ClientTimelineController::class)->name('clients.timeline');
