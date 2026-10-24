<?php

declare(strict_types=1);

namespace App\Domains\Billing\Enums;

/**
 * Which way the money went: paid by the member, or refunded to them. Both are
 * stored as payments with a positive amount, so one list and one sequence of
 * receipt numbers cover them. See ADR 0008.
 */
enum PaymentType: string
{
    case Payment = 'payment';
    case Refund = 'refund';
}
