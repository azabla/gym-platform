<?php

namespace App\Notifications;

use App\Domains\PlatformBilling\Models\GymInvoice;

/**
 * An invoice from the platform is a week past its due day. See ADR 0045.
 */
final class GymBillOverdue extends GymBillNotification
{
    public static function for(GymInvoice $invoice): self
    {
        return new self(details: self::detailsOf($invoice), data: self::dataOf($invoice));
    }

    public function databaseType(object $notifiable): string
    {
        return 'staff_bill_overdue';
    }
}
