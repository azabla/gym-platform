<?php

declare(strict_types=1);

namespace App\Domains\Billing\Exceptions;

use App\Domains\Shared\Money\Money;
use DomainException;

/**
 * A cash count that can't be closed or checked (ADR 0046). The field names
 * the form field the message belongs to.
 */
final class CashCountRefused extends DomainException
{
    private function __construct(string $message, public readonly string $field)
    {
        parent::__construct($message);
    }

    public static function nothingToCount(): self
    {
        return new self(__('There is no cash to count: none was taken at this branch since your last count.'), 'counted');
    }

    public static function differenceNeedsANote(Money $difference): self
    {
        return new self(__('The cash counted is :difference off. Say why in a note.', ['difference' => $difference->format()]), 'note');
    }

    public static function alreadyChecked(): self
    {
        return new self(__('This count is already checked.'), 'count');
    }

    public static function ownCount(): self
    {
        return new self(__('Someone else checks your own count.'), 'count');
    }
}
