<?php

declare(strict_types=1);

namespace App\Domains\Memberships\Exceptions;

use App\Domains\Memberships\Models\Plan;
use Carbon\CarbonImmutable;
use DomainException;

/**
 * A promo code that can't be used for this member and plan today. The
 * refusal checks on PromoCode decide, so the members app, the API and the
 * staff panel refuse the same codes. The field is always promo_code. See
 * ADR 0030.
 */
final class PromoCodeRefused extends DomainException
{
    public readonly string $field;

    private function __construct(string $message)
    {
        parent::__construct($message);

        $this->field = 'promo_code';
    }

    public static function unknown(): self
    {
        return new self(__('This promo code doesn\'t exist. Check how it is spelled.'));
    }

    public static function notStarted(CarbonImmutable $startsOn): self
    {
        return new self(__('This promo code can be used from :date.', ['date' => $startsOn->translatedFormat('j F Y')]));
    }

    public static function ended(CarbonImmutable $endsOn): self
    {
        return new self(__('This promo code ended on :date.', ['date' => $endsOn->translatedFormat('j F Y')]));
    }

    public static function usedUp(): self
    {
        return new self(__('This promo code has been used up.'));
    }

    public static function alreadyUsedByTheMember(): self
    {
        return new self(__('This promo code can be used once per member, and this member has used it.'));
    }

    public static function notForThePlan(Plan $plan): self
    {
        return new self(__('This promo code isn\'t for the :plan plan.', ['plan' => $plan->name]));
    }

    public static function takesTooMuchOff(Plan $plan): self
    {
        return new self(__('This promo code takes more off than the :plan plan costs.', ['plan' => $plan->name]));
    }

    public static function makesItFree(): self
    {
        return new self(__('This promo code makes the plan free. Show it at the desk to get your membership.'));
    }
}
