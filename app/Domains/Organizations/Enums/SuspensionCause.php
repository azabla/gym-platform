<?php

declare(strict_types=1);

namespace App\Domains\Organizations\Enums;

/**
 * Why a gym was paused: by a platform admin, or by the platform itself for an
 * unpaid invoice, which a payment ends (ADRs 0043 and 0045).
 */
enum SuspensionCause: string
{
    case ByAdmin = 'by_admin';
    case UnpaidBill = 'unpaid_bill';
}
