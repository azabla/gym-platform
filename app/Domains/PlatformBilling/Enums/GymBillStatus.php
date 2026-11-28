<?php

declare(strict_types=1);

namespace App\Domains\PlatformBilling\Enums;

/**
 * Where a gym stands with the platform's bill (ADR 0045): not billed (no
 * plan yet), on its free trial, paid up, with an invoice due (issued and not
 * yet past its due day), overdue, or paused for not paying.
 */
enum GymBillStatus: string
{
    case NotBilled = 'not_billed';
    case Trial = 'trial';
    case PaidUp = 'paid_up';
    case Due = 'due';
    case Overdue = 'overdue';
    case PausedUnpaid = 'paused_unpaid';

    public function label(): string
    {
        return match ($this) {
            self::NotBilled => 'Not billed',
            self::Trial => 'Trial',
            self::PaidUp => 'Paid up',
            self::Due => 'Due',
            self::Overdue => 'Overdue',
            self::PausedUnpaid => 'Paused, unpaid',
        };
    }
}
