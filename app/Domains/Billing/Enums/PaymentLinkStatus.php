<?php

declare(strict_types=1);

namespace App\Domains\Billing\Enums;

/**
 * Where a payment link stands:
 * - pending: waiting for the member to pay;
 * - paid: Chapa confirmed the payment, and it was recorded;
 * - cancelled: staff withdrew it, for example because the member paid another way;
 * - refund_needed: Chapa took the money when nothing was due any more, so the
 *   gym refunds it from its Chapa dashboard.
 *
 * See ADR 0009.
 */
enum PaymentLinkStatus: string
{
    case Pending = 'pending';
    case Paid = 'paid';
    case Cancelled = 'cancelled';
    case RefundNeeded = 'refund_needed';
}
