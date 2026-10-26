<?php

declare(strict_types=1);

namespace App\Domains\Memberships\Exceptions;

use App\Domains\Memberships\Models\Membership;
use App\Domains\Memberships\Models\MembershipFreeze;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Str;

/**
 * A sale, freeze or cancellation that the member's memberships don't allow.
 * The refusal checks on Membership decide, so the API and the staff panel
 * refuse the same things. The field names the request field the message
 * belongs to. See ADR 0018.
 */
final class MembershipRefused extends DomainException
{
    private function __construct(string $message, public readonly string $field)
    {
        parent::__construct($message);
    }

    public static function clashesWith(Membership $clash, CarbonImmutable $firstFreeDay): self
    {
        return new self(sprintf(
            'The member already has a membership from %s to %s. The first free start date is %s.',
            $clash->starts_on->format('j F Y'),
            $clash->ends_on->format('j F Y'),
            $firstFreeDay->format('j F Y'),
        ), 'starts_on');
    }

    public static function cancelled(): self
    {
        return new self('This membership is cancelled.', 'membership');
    }

    public static function alreadyCancelled(): self
    {
        return new self('This membership is already cancelled.', 'membership');
    }

    public static function alreadyEnded(): self
    {
        return new self('This membership has already ended.', 'membership');
    }

    public static function freezeEndsBeforeItStarts(): self
    {
        return new self('The last day of the freeze can\'t be before its first day.', 'ends_on');
    }

    public static function freezeTooLong(int $maximumDays): self
    {
        return new self("A freeze can last at most {$maximumDays} days.", 'ends_on');
    }

    public static function freezeOutsideTheMembership(Membership $membership): self
    {
        return new self(sprintf(
            'A freeze must start while the membership runs, from %s to %s.',
            $membership->starts_on->format('j F Y'),
            $membership->ends_on->format('j F Y'),
        ), 'starts_on');
    }

    public static function alreadyFrozen(MembershipFreeze $freeze): self
    {
        return new self(sprintf(
            'This membership is already frozen from %s to %s.',
            $freeze->starts_on->format('j F Y'),
            $freeze->ends_on->format('j F Y'),
        ), 'starts_on');
    }

    public static function noFreezeDaysLeft(int $daysLeft): self
    {
        return new self($daysLeft > 0
            ? "This membership can be frozen for {$daysLeft} more ".Str::plural('day', $daysLeft).'.'
            : 'This membership has no freeze days left.', 'ends_on');
    }

    public static function freezeAlreadyEnded(): self
    {
        return new self('This freeze has already ended.', 'freeze');
    }
}
