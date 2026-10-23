<?php

use App\Http\Controllers\Api\V1\Auth\TokenController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\Organizations\BranchController;
use App\Http\Controllers\Api\V1\Organizations\ChapaAccountController;
use App\Http\Controllers\Api\V1\Organizations\MemberController;
use App\Http\Controllers\Api\V1\Organizations\MembershipCancellationController;
use App\Http\Controllers\Api\V1\Organizations\MembershipController;
use App\Http\Controllers\Api\V1\Organizations\MembershipFreezeController;
use App\Http\Controllers\Api\V1\Organizations\PaymentController;
use App\Http\Controllers\Api\V1\Organizations\PlanController;
use App\Http\Controllers\Api\V1\Organizations\RefundController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->name('api.v1.')->group(function (): void {
    Route::post('auth/tokens', [TokenController::class, 'store'])
        ->middleware('throttle:login')
        ->name('auth.tokens.store');

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::delete('auth/tokens/current', [TokenController::class, 'destroy'])->name('auth.tokens.destroy');
        Route::get('me', MeController::class)->name('me');

        Route::prefix('organizations/{organization}')
            ->middleware('organization')
            ->name('organizations.')
            ->group(function (): void {
                Route::apiResource('branches', BranchController::class)->only(['index', 'show']);
                Route::apiResource('members', MemberController::class);
                Route::apiResource('plans', PlanController::class);
                Route::apiResource('members.memberships', MembershipController::class)
                    ->only(['index', 'store', 'show'])
                    ->shallow();
                Route::post('memberships/{membership}/cancellation', [MembershipCancellationController::class, 'store'])
                    ->name('memberships.cancellation.store');
                Route::post('memberships/{membership}/freezes', [MembershipFreezeController::class, 'store'])
                    ->name('memberships.freezes.store');
                Route::post('memberships/{membership}/freezes/{freeze}/end', [MembershipFreezeController::class, 'end'])
                    ->name('memberships.freezes.end')
                    ->scopeBindings();
                Route::post('memberships/{membership}/payments', [PaymentController::class, 'store'])
                    ->name('memberships.payments.store');
                Route::post('memberships/{membership}/refunds', [RefundController::class, 'store'])
                    ->name('memberships.refunds.store');
                Route::apiResource('payments', PaymentController::class)->only(['index', 'show']);
                Route::post('payments/{payment}/void', [PaymentController::class, 'void'])
                    ->name('payments.void');
                Route::apiSingleton('chapa-account', ChapaAccountController::class)->destroyable();
            });
    });
});
