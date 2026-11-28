<?php

namespace App\Notifications;

use App\Domains\PlatformBilling\Models\GymInvoice;

/**
 * The platform paused the gym for an unpaid invoice. See ADR 0045.
 */
final class GymPausedForUnpaidBill extends GymBillNotification
{
    public static function for(GymInvoice $invoice): self
    {
        return new self(details: self::detailsOf($invoice), data: self::dataOf($invoice));
    }

    public function databaseType(object $notifiable): string
    {
        return 'staff_bill_paused';
    }
}
