<?php

namespace App\Notifications;

use App\Domains\Billing\Models\Payment;
use App\Domains\Notifications\Enums\NotificationTopic;

/**
 * A payment towards the member's membership was recorded, at the desk or
 * through Chapa. It names the amount, the plan and the gym, never the
 * payment's reference.
 */
final class PaymentSucceeded extends MemberNotification
{
    public static function for(Payment $payment): self
    {
        $membership = $payment->membership;

        return new self(
            details: [
                'amount' => (string) $payment->amount,
                'plan' => $membership->plan->name,
                'gym' => $membership->organization->name,
            ],
            data: [
                'payment_id' => (string) $payment->id,
                'membership_id' => (string) $membership->id,
            ],
        );
    }

    public function databaseType(object $notifiable): string
    {
        return 'payment_succeeded';
    }

    protected function topic(): NotificationTopic
    {
        return NotificationTopic::Payments;
    }
}
