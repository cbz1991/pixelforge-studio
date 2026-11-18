<?php

use App\Http\Controllers\AdminReportController;
use App\Http\Controllers\CreditPurchaseController;
use App\Http\Controllers\GenerationController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard')->name('home');

Route::middleware('auth')->group(function () {
    Route::get('dashboard', [GenerationController::class, 'index'])->name('dashboard');
    Route::post('generations', [GenerationController::class, 'store'])
        ->middleware('throttle:20,1')
        ->name('generations.store');
    Route::post('credits', CreditPurchaseController::class)
        ->middleware('throttle:10,1')
        ->name('credits.purchase');

    Route::get('admin/report', AdminReportController::class)
        ->middleware('can:view-admin-report')
        ->name('admin.report');
});
