<?php

use App\Http\Controllers\ReleaseController;
use Illuminate\Support\Facades\Route;

Route::prefix('internal')->name('internal.')->group(function () {
    Route::get('/ping', [ReleaseController::class, 'ping'])->name('ping');
    Route::get('/check', [ReleaseController::class, 'check'])->name('check');
    Route::post('/reset', [ReleaseController::class, 'reset'])->name('reset');
    Route::post('/migrate', [ReleaseController::class, 'migrate'])->name('migrate');
});
