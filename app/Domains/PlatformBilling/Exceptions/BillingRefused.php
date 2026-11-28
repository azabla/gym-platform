<?php

declare(strict_types=1);

namespace App\Domains\PlatformBilling\Exceptions;

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
}
