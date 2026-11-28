<?php

declare(strict_types=1);

namespace App\Domains\PlatformBilling\Exceptions;

use App\Domains\Shared\Money\Money;
use DomainException;

/**
 * Something about a gym's bill that can't be done. Only platform admins set
 * up billing, record payments and void invoices, in the admin panel, which
 * is English, so the messages are not translated. See ADR 0045.
 */
final class BillingRefused extends DomainException
{
    public static function alreadyBilled(): self
    {
        return new self('This gym already has billing set up. Change its plan instead.');
    }

    public static function retiredPlan(): self
    {
        return new self('This plan is retired. Choose a plan that is offered.');
    }

    public static function firstDayInThePast(): self
    {
        return new self('The first billing day can\'t be before today, where the gym is.');
    }

    public static function notOnTrial(): self
    {
        return new self('This gym isn\'t on its trial any more.');
    }

    public static function trialNotLonger(): self
    {
        return new self('Choose a day after the trial\'s current end.');
    }

    public static function firstInvoiceIssued(): self
    {
        return new self('The first invoice after the trial is already issued. Void it first, then extend the trial.');
    }

    public static function noVoidReason(): self
    {
        return new self('Say why the invoice is voided, so the next admin knows.');
    }

    public static function alreadyVoided(): self
    {
        return new self('This invoice is already voided.');
    }

    public static function paidInvoice(): self
    {
        return new self('This invoice has payments on it, so it can\'t be voided.');
    }

    public static function alreadyPaid(): self
    {
        return new self('This invoice is already paid.');
    }

    public static function moreThanOwed(Money $owed): self
    {
        return new self('That is more than is owed on this invoice: '.$owed->format().'.');
    }

    public static function nothingPaid(): self
    {
        return new self('Enter the amount the gym paid.');
    }

    public static function referenceNeeded(): self
    {
        return new self('Enter the transaction number from the gym\'s receipt.');
    }

    public static function referenceUsed(): self
    {
        return new self('A payment with this transaction number is already recorded.');
    }

    public static function notOpen(): self
    {
        return new self('This invoice is paid or voided.');
    }

    public static function graceNotLater(): self
    {
        return new self('Choose a day after today, and not before the invoice\'s due day.');
    }

    public static function paidInTheFuture(): self
    {
        return new self('The day it was paid can\'t be after today.');
    }
}
