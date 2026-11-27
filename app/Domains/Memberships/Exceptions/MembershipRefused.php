<?php

declare(strict_types=1);

namespace App\Domains\Memberships\Exceptions;

use App\Domains\Memberships\Models\Membership;
use App\Domains\Memberships\Models\MembershipFreeze;
use Carbon\CarbonImmutable;
use DomainException;

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
        return new self(__('The member already has a membership from :from to :to. The first free start date is :first.', [
            'from' => $clash->starts_on->translatedFormat('j F Y'),
            'to' => $clash->ends_on->translatedFormat('j F Y'),
            'first' => $firstFreeDay->translatedFormat('j F Y'),
        ]), 'starts_on');
    }

    public static function cancelled(): self
    {
        return new self(__('This membership is cancelled.'), 'membership');
    }

    public static function alreadyCancelled(): self
    {
        return new self(__('This membership is already cancelled.'), 'membership');
    }

    public static function alreadyEnded(): self
    {
        return new self(__('This membership has already ended.'), 'membership');
    }

    public static function freezeEndsBeforeItStarts(): self
    {
        return new self(__('The last day of the freeze can\'t be before its first day.'), 'ends_on');
    }

    public static function freezeTooLong(int $maximumDays): self
    {
        return new self(__('A freeze can last at most :count days.', ['count' => $maximumDays]), 'ends_on');
    }

    public static function freezeOutsideTheMembership(Membership $membership): self
    {
        return new self(__('A freeze must start while the membership runs, from :from to :to.', [
            'from' => $membership->starts_on->translatedFormat('j F Y'),
            'to' => $membership->ends_on->translatedFormat('j F Y'),
        ]), 'starts_on');
    }

    public static function alreadyFrozen(MembershipFreeze $freeze): self
    {
        return new self(__('This membership is already frozen from :from to :to.', [
            'from' => $freeze->starts_on->translatedFormat('j F Y'),
            'to' => $freeze->ends_on->translatedFormat('j F Y'),
        ]), 'starts_on');
    }

    public static function noFreezeDaysLeft(int $daysLeft): self
    {
        return new self($daysLeft > 0
            ? trans_choice('{1} This membership can be frozen for 1 more day.|[2,*] This membership can be frozen for :count more days.', $daysLeft)
            : __('This membership has no freeze days left.'), 'ends_on');
    }

    public static function freezeInThePast(): self
    {
        return new self(__('A freeze can start today at the earliest.'), 'starts_on');
    }

    public static function freezeAlreadyEnded(): self
    {
        return new self(__('This freeze has already ended.'), 'freeze');
    }
}
