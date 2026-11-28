<?php

declare(strict_types=1);

namespace App\Domains\Billing\Exceptions;

use DomainException;

/**
 * An expense that can't be recorded or voided (ADR 0047). The field names
 * the form field the message belongs to.
 */
final class ExpenseRefused extends DomainException
{
    private function __construct(string $message, public readonly string $field)
    {
        parent::__construct($message);
    }

    public static function nothingSpent(): self
    {
        return new self(__('Enter how much was spent.'), 'amount');
    }

    public static function paidInTheFuture(): self
    {
        return new self(__('The day it was paid can\'t be after today.'), 'paid_on');
    }

    public static function notTheirBranch(): self
    {
        return new self(__('Choose a branch you work at.'), 'branch_id');
    }

    public static function wholeGymNotTheirs(): self
    {
        return new self(__('Only owners and accountants record expenses for the whole gym. Choose a branch.'), 'branch_id');
    }

    public static function categoryArchived(): self
    {
        return new self(__('This category is archived. Choose another.'), 'expense_category_id');
    }

    public static function methodNotForExpenses(): self
    {
        return new self(__('Choose cash, Telebirr, CBE Birr or a bank transfer.'), 'method');
    }

    public static function receiptTooLarge(): self
    {
        return new self(__('The receipt is too large: up to 5 MB.'), 'receipt');
    }

    public static function receiptNotAccepted(): self
    {
        return new self(__('The receipt must be a photo (JPEG, PNG or WebP) or a PDF.'), 'receipt');
    }

    public static function alreadyVoided(): self
    {
        return new self(__('This expense is already voided.'), 'expense');
    }

    public static function noReason(): self
    {
        return new self(__('Say why the expense is voided.'), 'reason');
    }
}
