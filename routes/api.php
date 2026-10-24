<?php

use App\Http\Controllers\Api\V1\Auth\SmsCodeController;
use App\Http\Controllers\Api\V1\Auth\SmsTokenController;
use App\Http\Controllers\Api\V1\Auth\TokenController;
use App\Http\Controllers\Api\V1\ChapaCallbackController;
use App\Http\Controllers\Api\V1\Me\MyAccessCredentialController;
use App\Http\Controllers\Api\V1\Me\MyCheckInController;
use App\Http\Controllers\Api\V1\Me\MyGymController;
use App\Http\Controllers\Api\V1\Me\MyMembershipController;
use App\Http\Controllers\Api\V1\Me\MyNotificationController;
use App\Http\Controllers\Api\V1\Me\MyNotificationPreferenceController;
use App\Http\Controllers\Api\V1\Me\MyPaymentController;
use App\Http\Controllers\Api\V1\Me\MyPaymentLinkController;
use App\Http\Controllers\Api\V1\Me\MyPushTokenController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\Organizations\BranchController;
use App\Http\Controllers\Api\V1\Organizations\ChapaAccountController;
use App\Http\Controllers\Api\V1\Organizations\CheckInController;
use App\Http\Controllers\Api\V1\Organizations\MemberController;
use App\Http\Controllers\Api\V1\Organizations\MembershipCancellationController;
use App\Http\Controllers\Api\V1\Organizations\MembershipController;
use App\Http\Controllers\Api\V1\Organizations\MembershipFreezeController;
use App\Http\Controllers\Api\V1\Organizations\PaymentController;
use App\Http\Controllers\Api\V1\Organizations\PaymentLinkController;
use App\Http\Controllers\Api\V1\Organizations\PlanController;
use App\Http\Controllers\Api\V1\Organizations\RefundController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->name('api.v1.')->group(function (): void {
    Route::post('auth/tokens', [TokenController::class, 'store'])
        ->middleware('throttle:login')
        ->name('auth.tokens.store');

    // Members sign in to their app with a code sent to their phone (ADR 0010).
    Route::post('auth/sms-codes', [SmsCodeController::class, 'store'])
        ->middleware('throttle:sms-codes')
        ->name('auth.sms-codes.store');
    Route::post('auth/sms-tokens', [SmsTokenController::class, 'store'])
        ->middleware('throttle:sms-sign-in')
        ->name('auth.sms-tokens.store');

    // Chapa calls this after a member pays; it only makes us ask Chapa (ADR 0009).
    Route::match(['get', 'post'], 'chapa/callback', ChapaCallbackController::class)
        ->middleware('throttle:60,1')
        ->name('chapa.callback');

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::delete('auth/tokens/current', [TokenController::class, 'destroy'])->name('auth.tokens.destroy');
        Route::get('me', MeController::class)->name('me');

        // This phone's push token from Firebase, for push notifications (ADR 0012).
        Route::put('me/push-token', [MyPushTokenController::class, 'update'])
            ->middleware('throttle:30,1')
            ->name('me.push-token.update');
        Route::delete('me/push-token', [MyPushTokenController::class, 'destroy'])->name('me.push-token.destroy');

        // The members app: the gyms where my phone number is registered, and my records at each (ADR 0010).
        Route::get('me/gyms', [MyGymController::class, 'index'])->name('me.gyms.index');

        Route::prefix('me/gyms/{organization}')
            ->middleware('member')
            ->name('me.gyms.')
            ->group(function (): void {
                Route::get('memberships', [MyMembershipController::class, 'index'])->name('memberships.index');
                Route::get('memberships/{membership}', [MyMembershipController::class, 'show'])
                    ->whereNumber('membership')
                    ->name('memberships.show');
                Route::get('payments', [MyPaymentController::class, 'index'])->name('payments.index');
                Route::get('check-ins', [MyCheckInController::class, 'index'])->name('check-ins.index');
                Route::get('notifications', [MyNotificationController::class, 'index'])->name('notifications.index');
                Route::post('notifications/read-all', [MyNotificationController::class, 'readAll'])->name('notifications.read-all');
                Route::post('notifications/{notification}/read', [MyNotificationController::class, 'read'])
                    ->whereUuid('notification')
                    ->name('notifications.read');
                Route::get('notification-preferences', [MyNotificationPreferenceController::class, 'show'])
                    ->name('notification-preferences.show');
                Route::patch('notification-preferences', [MyNotificationPreferenceController::class, 'update'])
                    ->name('notification-preferences.update');
                Route::get('payments/{payment}', [MyPaymentController::class, 'show'])
                    ->whereNumber('payment')
                    ->name('payments.show');
                Route::post('memberships/{membership}/payment-link', [MyPaymentLinkController::class, 'store'])
                    ->whereNumber('membership')
                    ->name('memberships.payment-link.store');
                Route::get('payment-links/{payment_link}', [MyPaymentLinkController::class, 'show'])
                    ->whereNumber('payment_link')
                    ->name('payment-links.show');
                Route::post('payment-links/{payment_link}/check', [MyPaymentLinkController::class, 'check'])
                    ->whereNumber('payment_link')
                    ->middleware('throttle:10,1')
                    ->name('payment-links.check');
                Route::post('access-credential', [MyAccessCredentialController::class, 'store'])
                    ->middleware('throttle:10,1')
                    ->name('access-credential.store');
                Route::get('access-credential', [MyAccessCredentialController::class, 'show'])
                    ->name('access-credential.show');
            });

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
                Route::post('memberships/{membership}/payment-links', [PaymentLinkController::class, 'store'])
                    ->name('memberships.payment-links.store');
                Route::apiResource('payment-links', PaymentLinkController::class)->only(['index', 'show']);
                Route::post('payment-links/{payment_link}/cancel', [PaymentLinkController::class, 'cancel'])
                    ->name('payment-links.cancel');
                Route::post('payment-links/{payment_link}/check', [PaymentLinkController::class, 'check'])
                    ->name('payment-links.check');
                Route::get('check-ins', [CheckInController::class, 'index'])
                    ->name('check-ins.index');
                Route::post('check-ins/scan', [CheckInController::class, 'scan'])
                    ->name('check-ins.scan');
                Route::post('members/{member}/check-ins', [CheckInController::class, 'store'])
                    ->name('members.check-ins.store');
            });
    });
});
