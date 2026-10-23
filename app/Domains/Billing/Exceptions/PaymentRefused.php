<?php

declare(strict_types=1);

namespace App\Domains\Billing\Exceptions;

use App\Domains\Shared\Money\Money;
use DomainException;

/**
 * A payment, refund or void that the membership's money doesn't allow. The
 * refusal checks on Payment decide: requests report a refusal as a 422, and
 * the domain methods check again with the membership locked, in case another
 * desk got in first. The field names the request field the message belongs to.
 */
final class PaymentRefused extends DomainException
{
    private function __construct(string $message, public readonly string $field)
    {
        parent::__construct($message);
    }

    public static function membershipCancelled(): self
    {
        return new self('This membership is cancelled, so nothing is due on it.', 'membership');
    }

    public static function moreThanBalanceDue(Money $balanceDue): self
    {
        return new self($balanceDue->isPositive()
            ? "Only {$balanceDue->format()} is still due on this membership."
            : 'Nothing is due on this membership.', 'amount');
    }

    public static function membershipNotCancelled(): self
    {
        return new self('Only a cancelled membership can be refunded. Cancel it first.', 'membership');
    }

    public static function moreThanAmountPaid(Money $amountPaid): self
    {
        return new self($amountPaid->isPositive()
            ? "Only {$amountPaid->format()} has been paid towards this membership."
            : 'Nothing has been paid towards this membership.', 'amount');
    }

    public static function alreadyVoided(): self
    {
        return new self('This payment is already voided.', 'payment');
    }

    public static function voidLeavesMoreRefundedThanPaid(): self
    {
        return new self('Voiding this payment would leave more refunded than paid. Void the refund first.', 'payment');
    }
}
