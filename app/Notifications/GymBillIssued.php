<?php

namespace App\Notifications;

use App\Domains\PlatformBilling\Models\GymInvoice;

/**
 * The platform issued an invoice to the gym. See ADR 0045.
 */
final class GymBillIssued extends GymBillNotification
{
    public static function for(GymInvoice $invoice): self
    {
        return new self(details: self::detailsOf($invoice), data: self::dataOf($invoice));
    }

    public function databaseType(object $notifiable): string
    {
        return 'staff_bill_issued';
    }
}
