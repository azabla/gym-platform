<?php

declare(strict_types=1);

namespace App\Domains\Organizations\Exceptions;

use DomainException;

/**
 * A closure a branch can't have: in the past, too long, at a branch the
 * staff member doesn't work at, or over days the branch already has one.
 * The field names the request field the message belongs to. See ADR 0037.
 */
final class BranchClosureRefused extends DomainException
{
    private function __construct(string $message, public readonly string $field)
    {
        parent::__construct($message);
    }

    public static function inThePast(): self
    {
        return new self(__('A closure can start today at the earliest.'), 'starts_on');
    }

    public static function tooLong(int $maximumDays): self
    {
        return new self(__('A closure can last :count days at most; add another for more.', ['count' => $maximumDays]), 'ends_on');
    }

    public static function notAtYourBranch(string $branchName): self
    {
        return new self(__('You don\'t work at :branch.', ['branch' => $branchName]), 'branch_ids');
    }

    public static function overlaps(string $branchName): self
    {
        return new self(__(':branch already has a closure on some of these days.', ['branch' => $branchName]), 'starts_on');
    }
}
