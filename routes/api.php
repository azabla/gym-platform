<?php

use App\Http\Controllers\Api\V1\Auth\TokenController;
use App\Http\Controllers\Api\V1\MeController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->name('api.v1.')->group(function (): void {
    Route::post('auth/tokens', [TokenController::class, 'store'])
        ->middleware('throttle:login')
        ->name('auth.tokens.store');

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::delete('auth/tokens/current', [TokenController::class, 'destroy'])->name('auth.tokens.destroy');
        Route::get('me', MeController::class)->name('me');
    });
});
