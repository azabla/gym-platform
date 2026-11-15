<?php

namespace App\Notifications;

use App\Domains\Billing\Models\Payment;

/**
 * A member paid online, through Chapa: a payment link paid, or a plan bought
 * in the app (ADR 0009, ADR 0013 and ADR 0027). It opens the payment.
 */
final class OnlinePaymentReceived extends StaffNotification
{
    public static function for(Payment $payment): self
    {
        return new self(
            details: [
                'member' => (string) $payment->member?->full_name,
                'amount' => $payment->amount->format(),
                'plan' => (string) $payment->membership?->plan?->name,
                'receipt' => $payment->receipt_number,
            ],
            data: [
                'payment_id' => (string) $payment->id,
                'membership_id' => (string) $payment->membership_id,
            ],
        );
    }

    public function databaseType(object $notifiable): string
    {
        return 'staff_online_payment';
    }
}
