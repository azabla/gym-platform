<?php

namespace App\Notifications;

use App\Domains\PlatformBilling\Models\GymInvoice;

/**
 * The gym finished paying an invoice from the platform; if the invoice had
 * paused the gym, it says the gym is open again. See ADR 0045.
 */
final class GymBillPaid extends GymBillNotification
{
    public static function for(GymInvoice $invoice, bool $reopened): self
    {
        return new self(
            details: [...self::detailsOf($invoice), 'amount' => $invoice->total->format(), 'reopened' => $reopened],
            data: self::dataOf($invoice),
        );
    }

    public function databaseType(object $notifiable): string
    {
        return ($this->details['reopened'] ?? false) === true ? 'staff_bill_paid_reopened' : 'staff_bill_paid';
    }
}
