<?php

declare(strict_types=1);

namespace App\Domains\Billing\Enums;

/**
 * How the money was paid. Every method but cash has a transaction number
 * (reference): staff copy it from the confirmation, such as Telebirr's SMS,
 * except for Chapa, whose payments are recorded only when Chapa itself
 * confirms them, with Chapa's reference. See ADR 0008 and ADR 0009.
 *
 * Brought in is money a member paid before the gym joined the platform,
 * recorded when the gym imports its members: it counts towards what they
 * still owe, never towards money taken, and is never chosen by hand
 * (ADR 0044).
 */
enum PaymentMethod: string
{
    case Cash = 'cash';
    case Telebirr = 'telebirr';
    case CbeBirr = 'cbe_birr';
    case BankTransfer = 'bank_transfer';
    case Chapa = 'chapa';
    case BroughtIn = 'brought_in';

    public function hasReference(): bool
    {
        return $this !== self::Cash && $this !== self::BroughtIn;
    }

    /**
     * Paid online and confirmed by the payment provider, so never recorded by
     * hand and never received by a staff member.
     */
    public function isOnline(): bool
    {
        return $this === self::Chapa;
    }

    /**
     * Paid before the gym joined the platform, and recorded by an import.
     */
    public function isBroughtIn(): bool
    {
        return $this === self::BroughtIn;
    }

    /**
     * What staff can choose at the desk: not Chapa, which Chapa confirms,
     * and not brought in, which only an import records.
     *
     * @return list<self>
     */
    public static function forTheDesk(): array
    {
        return [self::Cash, self::Telebirr, self::CbeBirr, self::BankTransfer];
    }

    /**
     * The methods that count as money taken, in takings, reports and
     * insights: every one but brought in.
     *
     * @return list<self>
     */
    public static function moneyTaken(): array
    {
        return array_values(array_filter(self::cases(), fn (self $method): bool => ! $method->isBroughtIn()));
    }

    /**
     * The method in words, as the staff panel and reports show it.
     */
    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Cash',
            self::Telebirr => 'telebirr',
            self::CbeBirr => 'CBE Birr',
            self::BankTransfer => 'Bank transfer',
            self::Chapa => 'Chapa',
            self::BroughtIn => 'Brought in',
        };
    }
}
