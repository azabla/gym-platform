<?php

declare(strict_types=1);

namespace App\Domains\Memberships\Pricing;

use App\Domains\Members\Models\Member;
use App\Domains\Memberships\Discount\Exceptions\InvalidDiscount;
use App\Domains\Memberships\Models\MemberDiscount;
use App\Domains\Memberships\Models\Plan;
use App\Domains\Memberships\Models\PromoCode;
use Carbon\CarbonImmutable;

/**
 * What a member pays for a plan today: the plan's price, their discount
 * group's price, or a promo code's, whichever is lowest, never two together.
 * A tie goes to the group, which leaves the code unused. A group that takes
 * more off than the plan costs doesn't apply to it. The code must already be
 * one the member may use (PromoCode::refusalToUse). See ADR 0032.
 */
final class Pricing
{
    public static function bestFor(Member $member, Plan $plan, CarbonImmutable $today, ?PromoCode $promoCode = null): PriceChoice
    {
        $choice = PriceChoice::full($plan->price);
        $group = MemberDiscount::groupFor($member, $today);

        if ($group !== null) {
            try {
                $choice = PriceChoice::withGroup($plan->price, $group->discount()->appliedTo($plan->price), $group);
            } catch (InvalidDiscount) {
                // The group takes more off than this plan costs, so it doesn't apply to it.
            }
        }

        $codePrice = $promoCode?->priceIfFor($plan);

        if ($promoCode !== null && $codePrice !== null && $codePrice->isLessThan($choice->price)) {
            $choice = PriceChoice::withCode($plan->price, $codePrice, $promoCode);
        }

        return $choice;
    }
}
