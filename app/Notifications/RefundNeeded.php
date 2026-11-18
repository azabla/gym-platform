<?php

namespace App\Notifications;

use App\Domains\Billing\Models\PaymentLink;
use App\Domains\Notifications\Enums\StaffNotificationTopic;

/**
 * Chapa took money that wasn't due any more: the gym refunds it from its
 * Chapa dashboard (ADR 0009 and ADR 0027). The panel opens the link (ADR 0029).
 */
final class RefundNeeded extends StaffNotification
{
    public static function for(PaymentLink $paymentLink): self
    {
        return new self(
            details: [
                'member' => (string) $paymentLink->member?->full_name,
                'amount' => $paymentLink->amount->format(),
            ],
            data: [
                'payment_link_id' => (string) $paymentLink->id,
                'member_id' => (string) $paymentLink->member_id,
            ],
        );
    }

    public function databaseType(object $notifiable): string
    {
        return 'staff_refund_needed';
    }

    public function topic(): StaffNotificationTopic
    {
        return StaffNotificationTopic::Refunds;
    }
}
