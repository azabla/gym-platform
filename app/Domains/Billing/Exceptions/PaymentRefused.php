<?php

declare(strict_types=1);

namespace App\Domains\Billing\Exceptions;

use App\Domains\Shared\Money\Money;
use DomainException;

/**
 * A payment that the membership's balance no longer allows. Requests check the
 * same rules first, with the same messages; this is the safety net for two
 * desks taking a payment for one membership at the same moment. The field
 * names the request field the message belongs to.
 */
final class PaymentRefused extends DomainException
{
    private function __construct(string $message, public readonly string $field)
    {
        parent::__construct($message);
    }

    public static function moreThanBalanceDue(Money $balanceDue): self
    {
        return new self(self::balanceDueMessage($balanceDue), 'amount');
    }

    /**
     * What a request says when a payment is more than the balance due.
     */
    public static function balanceDueMessage(Money $balanceDue): string
    {
        return $balanceDue->isPositive()
            ? "Only {$balanceDue->format()} is still due on this membership."
            : 'Nothing is due on this membership.';
    }
}
