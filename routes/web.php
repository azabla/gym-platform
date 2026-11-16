<?php

use App\Http\Controllers\Staff\ForgotStaffPasswordController;
use App\Http\Controllers\Staff\StaffPasswordController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Where Chapa sends a member after paying: back to the app, which has the API confirm the payment (ADR 0022).
Route::view('payments/returned', 'payments.returned')->name('payments.returned');

// Staff set their password from a link sent by SMS, and ask for one when they forget it (ADR 0019).
Route::prefix('password')->name('staff.password.')->group(function (): void {
    Route::get('forgot', [ForgotStaffPasswordController::class, 'create'])->name('forgot');
    Route::post('forgot', [ForgotStaffPasswordController::class, 'store'])
        ->middleware('throttle:password-links')
        ->name('send');
    Route::get('saved', [StaffPasswordController::class, 'done'])->name('done');
    Route::get('{token}', [StaffPasswordController::class, 'edit'])->name('edit');
    Route::post('{token}', [StaffPasswordController::class, 'update'])
        ->middleware('throttle:10,1')
        ->name('update');
});
