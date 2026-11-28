<?php

declare(strict_types=1);

namespace App\Domains\PlatformBilling\Enums;

/**
 * How a gym paid the platform (ADR 0045). Every way but cash has a
 * transaction number from the gym's receipt.
 */
enum GymPaymentMethod: string
{
    case BankTransfer = 'bank_transfer';
    case Telebirr = 'telebirr';
    case CbeBirr = 'cbe_birr';
    case Cash = 'cash';

    public function label(): string
    {
        return match ($this) {
            self::BankTransfer => 'Bank transfer',
            self::Telebirr => 'Telebirr',
            self::CbeBirr => 'CBE Birr',
            self::Cash => 'Cash',
        };
    }

    public function hasReference(): bool
    {
        return $this !== self::Cash;
    }
}
