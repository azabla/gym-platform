<?php

declare(strict_types=1);

namespace App\Domains\Billing\Enums;

/**
 * How the money was paid. Every method but cash has a transaction number
 * (reference) that staff copy from the confirmation, such as Telebirr's SMS.
 * See ADR 0008.
 */
enum PaymentMethod: string
{
    case Cash = 'cash';
    case Telebirr = 'telebirr';
    case CbeBirr = 'cbe_birr';
    case BankTransfer = 'bank_transfer';

    public function hasReference(): bool
    {
        return $this !== self::Cash;
    }
}
