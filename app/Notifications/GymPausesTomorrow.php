<?php

namespace App\Notifications;

use App\Domains\PlatformBilling\Models\GymInvoice;

/**
 * An unpaid invoice pauses the gym tomorrow. See ADR 0045.
 */
final class GymPausesTomorrow extends GymBillNotification
{
    public static function for(GymInvoice $invoice): self
    {
        return new self(details: self::detailsOf($invoice), data: self::dataOf($invoice));
    }

    public function databaseType(object $notifiable): string
    {
        return 'staff_bill_pauses_tomorrow';
    }
}
