<?php

declare(strict_types=1);

namespace App\Domains\Billing\Enums;

/**
 * How the money was paid. Every method but cash has a transaction number
 * (reference): staff copy it from the confirmation, such as Telebirr's SMS,
 * except for Chapa, whose payments are recorded only when Chapa itself
 * confirms them, with Chapa's reference. See ADR 0008 and ADR 0009.
 */
enum PaymentMethod: string
{
    case Cash = 'cash';
    case Telebirr = 'telebirr';
    case CbeBirr = 'cbe_birr';
    case BankTransfer = 'bank_transfer';
    case Chapa = 'chapa';

    public function hasReference(): bool
    {
        return $this !== self::Cash;
    }

    /**
     * Paid online and confirmed by the payment provider, so never recorded by
     * hand and never received by a staff member.
     */
    public function isOnline(): bool
    {
        return $this === self::Chapa;
    }
}
