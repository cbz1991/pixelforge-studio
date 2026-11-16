<?php

use App\Http\Controllers\GenerationController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard')->name('home');

Route::middleware('auth')->group(function () {
    Route::get('dashboard', [GenerationController::class, 'index'])->name('dashboard');
    Route::post('generations', [GenerationController::class, 'store'])
        ->middleware('throttle:20,1')
        ->name('generations.store');
});

require __DIR__.'/settings.php';
